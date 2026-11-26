<?php

namespace App\Console\Commands;

use App\Models\BaseModel;
use App\Services\InferenceRunner;
use App\Services\JobQueue;
use App\Services\Settings;
use App\Support\RequestId;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single inference worker (ADR D7). Holds an exclusive flock for its lifetime; only
 * the lock holder may recover orphaned leases, which proves the previous worker
 * process has exited. Processes one job at a time.
 */
class Work extends Command
{
    protected $signature = 'gateway:work
        {--once : Process at most one job and exit}
        {--stop-when-empty : Exit when no claimable job is found}
        {--max-jobs=0 : Exit after N jobs (0 = unlimited)}
        {--max-time=0 : Exit after N seconds (0 = unlimited); systemd restarts us}';

    protected $description = 'Run the single inference worker';

    private bool $shouldStop = false;

    public function handle(JobQueue $queue, InferenceRunner $runner, Settings $settings): int
    {
        $lockPath = (string) config('gateway.worker.lock_path');
        @mkdir(dirname($lockPath), 0700, true);
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another gateway worker holds the lock; refusing to start.');

            return self::FAILURE;
        }

        $workerId = gethostname().':'.getmypid().':'.Str::lower(Str::random(6));
        $this->trapSignals();
        $startedAt = time();
        $processed = 0;

        $recovered = $queue->recoverOrphans($workerId, all: true);
        Log::info('worker started', ['worker_id' => $workerId, 'recovered' => $recovered]);
        $this->line("worker {$workerId} started; recovered {$recovered} orphaned job(s)");

        try {
            while (! $this->shouldStop) {
                RequestId::set((string) Str::uuid());
                $settings->refresh();
                $this->heartbeat($workerId, null, $startedAt);
                $queue->expireStale();
                $queue->recoverOrphans($workerId);

                $job = $queue->claim($workerId);
                if ($job === null) {
                    if ($this->option('once') || $this->option('stop-when-empty')) {
                        break;
                    }
                    usleep((int) config('gateway.worker.idle_sleep_ms') * 1000);
                } else {
                    RequestId::set($job->request_id);
                    $this->heartbeat($workerId, $job->id, $startedAt);
                    $result = $runner->run($job, $workerId);
                    $processed++;
                    Log::info('job processed', ['job_id' => $job->id, 'result' => $result]);
                    if ($result !== 'completed') {
                        // Let Ollama finish aborting a cancelled generation before the next claim.
                        sleep((int) config('gateway.worker.post_timeout_cooldown_seconds'));
                    }
                    if ($this->option('once')) {
                        break;
                    }
                }

                $max = (int) $this->option('max-jobs');
                $maxTime = (int) $this->option('max-time');
                if (($max > 0 && $processed >= $max) || ($maxTime > 0 && time() - $startedAt >= $maxTime)) {
                    break;
                }
            }
        } finally {
            DB::table('worker_heartbeats')->where('worker_id', $workerId)->delete();
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->line("worker {$workerId} stopped after {$processed} job(s)");

        return self::SUCCESS;
    }

    private function heartbeat(string $workerId, ?string $jobId, int $startedAt): void
    {
        DB::table('worker_heartbeats')->updateOrInsert(['worker_id' => $workerId], [
            'pid' => getmypid(),
            'current_job_id' => $jobId,
            'started_at' => BaseModel::dbTime(CarbonImmutable::createFromTimestampUTC($startedAt)),
            'heartbeat_at' => BaseModel::dbTime(now()),
        ]);
    }

    private function trapSignals(): void
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT, SIGQUIT] as $sig) {
                pcntl_signal($sig, function () {
                    $this->shouldStop = true; // finish current job, then exit
                });
            }
        }
    }
}
