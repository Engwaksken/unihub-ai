<?php

namespace App\Http\Controllers\Management;

use App\Models\BaseModel;
use App\Models\Job;
use App\Models\JobPayload;
use App\Services\JobQueue;
use App\Services\PayloadCipher;
use App\Services\Settings;
use App\Support\ApiException;
use App\Support\RequestId;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Job metadata and queue operations. Payloads are never returned. */
class JobAdminController extends ManagementController
{
    public function index(Request $request): JsonResponse
    {
        $q = Job::query()->with('taskVersion.task', 'model');
        $this->actor($request)->scopeQuery($q, 'job.view');
        if ($p = $request->query('project_id')) {
            $q->where('project_id', $p);
        }
        if ($s = $request->query('state')) {
            $q->whereIn('state', array_intersect(explode(',', $s), Job::STATES) ?: ['__none__']);
        }
        if ($request->query('from') || $request->query('to')) {
            [$from, $to] = $this->range($request);
            $q->whereBetween('created_at', [BaseModel::dbTime($from), BaseModel::dbTime($to)]);
        }

        return $this->page($request, $q, fn (Job $j) => $j->toMetadata());
    }

    public function cancel(Request $request, string $id, JobQueue $queue): JsonResponse
    {
        $job = $this->find($request, $id, 'job.operate');
        $result = $queue->cancel($job);
        if ($result === 'invalid_state') {
            throw ApiException::invalidState('Only queued jobs can be cancelled');
        }
        if ($result === 'cancelled') {
            $this->audit($request, 'job.cancelled', 'job', $job->id, $job->project_id);
        }

        return $this->data($job->fresh(['taskVersion.task', 'model'])->toMetadata());
    }

    /**
     * Retry = new job linked by retry_of, only for failed jobs with a transient error
     * whose input has not been purged. Counts against queue capacity, not quota.
     */
    public function retry(Request $request, string $id, PayloadCipher $cipher, Settings $settings): JsonResponse
    {
        $job = $this->find($request, $id, 'job.operate');
        $data = $this->validate($request->json()->all(), ['reason' => 'required|string|min:3|max:200']);

        $new = DB::transaction(function () use ($job, $cipher, $settings) {
            $job->refresh();
            $payload = JobPayload::find($job->id);
            if ($job->state !== Job::FAILED || ! $job->error_transient || $payload === null) {
                throw ApiException::invalidState('Only failed jobs with a transient error and retained input can be retried');
            }
            if (Job::where('retry_of', $job->id)->exists()) {
                throw ApiException::invalidState('Job was already retried');
            }
            if (Job::where('state', Job::QUEUED)->count() >= $settings->int('global_queue_limit')) {
                throw new ApiException('QUEUE_FULL', 429, 'Queue is full', [], 30);
            }
            $input = $cipher->decrypt($job->id, 'input', $payload->input_ct, $payload->key_id);
            $now = CarbonImmutable::now('UTC');
            $newId = (string) Str::uuid7();
            $new = new Job;
            $new->forceFill([
                'id' => $newId,
                'project_id' => $job->project_id,
                'api_key_id' => $job->api_key_id,
                'task_version_id' => $job->task_version_id,
                'model_id' => $job->model_id,
                'state' => Job::QUEUED,
                'idem_key' => 'retry:'.$job->id,
                'input_hash' => $job->input_hash,
                'max_tokens' => $job->max_tokens,
                'est_input_tokens' => $job->est_input_tokens,
                'attempts' => 0,
                'available_at' => $now,
                'queued_at' => $now,
                'queue_deadline_at' => $now->addSeconds($settings->int('queue_wait_max_seconds')),
                'retry_of' => $job->id,
                'request_id' => RequestId::current(),
            ])->save();
            [$ct, $keyId] = $cipher->encrypt($newId, 'input', $input);
            JobPayload::create(['job_id' => $newId, 'key_id' => $keyId, 'input_ct' => $ct, 'created_at' => BaseModel::dbTime($now)]);

            return $new;
        });
        $this->audit($request, 'job.retried', 'job', $job->id, $job->project_id, ['new_job_id' => $new->id, 'reason' => $data['reason']]);

        return $this->data($new->fresh(['taskVersion.task', 'model'])->toMetadata(), 201);
    }

    private function find(Request $request, string $id, string $capability): Job
    {
        $job = Job::find($this->uuid($id)) ?? throw ApiException::notFound();
        $this->actor($request)->authorize($capability, $job->project_id);

        return $job;
    }
}
