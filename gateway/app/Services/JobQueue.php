<?php

namespace App\Services;

use App\Models\BaseModel;
use App\Models\Job;
use App\Models\UsageEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Job state transitions (ADR D7). Every transition is a conditional UPDATE on the
 * expected current state inside an IMMEDIATE transaction, so no job can complete
 * twice or be claimed by two workers.
 *
 *   queued → running → completed | failed
 *   queued → cancelled | expired
 *   running → queued (lease recovery / transient retry, bounded by max_attempts)
 */
class JobQueue
{
    public function __construct(private readonly Settings $settings) {}

    /** Fair claim: project least recently served first, then its oldest job. */
    public function claim(string $workerId): ?Job
    {
        return DB::transaction(function () use ($workerId) {
            $now = CarbonImmutable::now('UTC');
            $nowDb = BaseModel::dbTime($now);

            $projectId = DB::table('jobs as j')
                ->join('projects as p', 'p.id', '=', 'j.project_id')
                ->where('j.state', Job::QUEUED)
                ->where('j.available_at', '<=', $nowDb)
                ->where('j.queue_deadline_at', '>', $nowDb)
                ->groupBy('j.project_id', 'p.last_served_at')
                ->orderByRaw('p.last_served_at IS NOT NULL, p.last_served_at ASC, MIN(j.created_at) ASC')
                ->value('j.project_id');
            if ($projectId === null) {
                return null;
            }

            $job = Job::where('project_id', $projectId)
                ->where('state', Job::QUEUED)
                ->where('available_at', '<=', $nowDb)
                ->where('queue_deadline_at', '>', $nowDb)
                ->orderBy('created_at')->orderBy('id')
                ->first();

            $leaseSeconds = $this->settings->int('inference_deadline_seconds') + (int) config('gateway.worker.lease_grace_seconds');
            $updated = Job::whereKey($job->id)->where('state', Job::QUEUED)->update([
                'state' => Job::RUNNING,
                'attempts' => $job->attempts + 1,
                'worker_id' => $workerId,
                'lease_until' => BaseModel::dbTime($now->addSeconds($leaseSeconds)),
                'started_at' => $nowDb,
                'updated_at' => $nowDb,
            ]);
            if ($updated !== 1) {
                return null;
            }
            DB::table('projects')->where('id', $projectId)->update(['last_served_at' => $nowDb]);

            return $job->fresh(['taskVersion.task', 'model', 'payload']);
        });
    }

    /** Commit result + usage atomically. Returns false if this worker no longer owns the job. */
    public function complete(Job $job, string $workerId, string $outputCt, ?int $tokensIn, ?int $tokensOut): bool
    {
        return DB::transaction(function () use ($job, $workerId, $outputCt, $tokensIn, $tokensOut) {
            $now = CarbonImmutable::now('UTC');
            $updated = Job::whereKey($job->id)->where('state', Job::RUNNING)->where('worker_id', $workerId)->update([
                'state' => Job::COMPLETED,
                'finished_at' => BaseModel::dbTime($now),
                'lease_until' => null,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut,
                'error_code' => null,
                'error_message' => null,
                'error_transient' => false,
                'expires_at' => BaseModel::dbTime($now->addHours($this->settings->int('result_ttl_hours'))),
                'updated_at' => BaseModel::dbTime($now),
            ]);
            if ($updated !== 1) {
                return false;
            }
            DB::table('job_payloads')->where('job_id', $job->id)->update(['output_ct' => $outputCt]);
            $this->recordUsage($job->fresh(), 'completed', null);

            return true;
        });
    }

    /**
     * Failure handling: transient failures with attempts left are re-queued once
     * with backoff; everything else fails permanently (no unbounded retries).
     */
    public function fail(Job $job, string $workerId, string $code, string $message, bool $transient): string
    {
        return DB::transaction(function () use ($job, $workerId, $code, $message, $transient) {
            $fresh = Job::whereKey($job->id)->where('state', Job::RUNNING)->where('worker_id', $workerId)->first();
            if ($fresh === null) {
                return 'lost';
            }

            return $this->failOrRequeue($fresh, $code, $message, $transient);
        });
    }

    private function failOrRequeue(Job $job, string $code, string $message, bool $transient): string
    {
        $now = CarbonImmutable::now('UTC');
        $maxAttempts = (int) config('gateway.worker.max_attempts');

        if ($transient && $job->attempts < $maxAttempts) {
            Job::whereKey($job->id)->update([
                'state' => Job::QUEUED,
                'worker_id' => null,
                'lease_until' => null,
                'started_at' => null,
                'available_at' => BaseModel::dbTime($now->addSeconds((int) config('gateway.worker.retry_backoff_seconds'))),
                'queue_deadline_at' => BaseModel::dbTime($now->addSeconds($this->settings->int('queue_wait_max_seconds'))),
                'error_code' => $code,
                'error_message' => $message,
                'error_transient' => true,
                'updated_at' => BaseModel::dbTime($now),
            ]);

            return 'requeued';
        }

        Job::whereKey($job->id)->update([
            'state' => Job::FAILED,
            'worker_id' => null,
            'lease_until' => null,
            'finished_at' => BaseModel::dbTime($now),
            'error_code' => $code,
            'error_message' => $message,
            'error_transient' => $transient,
            'expires_at' => BaseModel::dbTime($now->addHours($this->settings->int('result_ttl_hours'))),
            'updated_at' => BaseModel::dbTime($now),
        ]);
        $this->recordUsage($job->fresh(), 'failed', $code);

        return 'failed';
    }

    /**
     * Recover running jobs whose owner is gone. Callers MUST hold the single-worker
     * lock (proof the previous worker process has stopped). With $all=true every
     * running job not owned by $currentWorker is recovered (startup); otherwise only
     * expired leases.
     */
    public function recoverOrphans(string $currentWorker, bool $all = false): int
    {
        return DB::transaction(function () use ($currentWorker, $all) {
            $q = Job::where('state', Job::RUNNING)->where(fn ($q) => $q->whereNull('worker_id')->orWhere('worker_id', '!=', $currentWorker));
            if (! $all) {
                $q->where('lease_until', '<', BaseModel::dbTime(now()));
            }
            $count = 0;
            foreach ($q->get() as $job) {
                $this->failOrRequeue($job, 'WORKER_LOST', 'Worker stopped while processing', true);
                $count++;
            }

            return $count;
        });
    }

    /** Queued jobs past their queue deadline → expired (QUEUE_TIMEOUT). */
    public function expireStale(): int
    {
        $now = BaseModel::dbTime(now());

        return Job::where('state', Job::QUEUED)->where('queue_deadline_at', '<=', $now)->update([
            'state' => Job::EXPIRED,
            'finished_at' => $now,
            'error_code' => 'QUEUE_TIMEOUT',
            'error_message' => 'Job waited too long in queue',
            'expires_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** queued → cancelled; idempotent for already-cancelled; otherwise INVALID_STATE. */
    public function cancel(Job $job): string
    {
        return DB::transaction(function () use ($job) {
            $now = BaseModel::dbTime(now());
            $updated = Job::whereKey($job->id)->where('state', Job::QUEUED)->update([
                'state' => Job::CANCELLED, 'finished_at' => $now, 'expires_at' => $now, 'updated_at' => $now,
            ]);
            if ($updated === 1) {
                return 'cancelled';
            }

            return Job::whereKey($job->id)->value('state') === Job::CANCELLED ? 'already_cancelled' : 'invalid_state';
        });
    }

    private function recordUsage(Job $job, string $status, ?string $errorCode): void
    {
        $queued = $job->queued_at;
        $started = $job->started_at ?? $job->finished_at;
        $finished = $job->finished_at ?? now();
        $job->loadMissing('taskVersion.task');

        UsageEvent::insertOrIgnore([[
            'id' => (string) Str::uuid7(),
            'job_id' => $job->id,
            'project_id' => $job->project_id,
            'model_id' => $job->model_id,
            'task_key' => $job->taskVersion->task->key,
            'status' => $status,
            'error_code' => $errorCode,
            'tokens_in' => $job->tokens_in,
            'tokens_out' => $job->tokens_out,
            'queue_ms' => max(0, (int) $queued->diffInMilliseconds($started)),
            'inference_ms' => max(0, (int) $started->diffInMilliseconds($finished)),
            'total_ms' => max(0, (int) $queued->diffInMilliseconds($finished)),
            'created_at' => BaseModel::dbTime(now()),
        ]]);
    }
}
