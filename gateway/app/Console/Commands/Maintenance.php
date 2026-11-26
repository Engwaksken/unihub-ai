<?php

namespace App\Console\Commands;

use App\Models\BaseModel;
use App\Services\JobQueue;
use App\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Retention purge (PRD §08): payloads at expiry, metadata after retention period. */
class Maintenance extends Command
{
    protected $signature = 'gateway:purge';

    protected $description = 'Purge expired payloads, old metadata, nonces and samples';

    public function handle(Settings $settings, JobQueue $queue): int
    {
        $now = now();
        $queue->expireStale();

        $payloads = DB::table('job_payloads')->whereIn('job_id', function ($q) use ($now) {
            $q->select('id')->from('jobs')->whereIn('state', ['completed', 'failed', 'cancelled', 'expired'])
                ->where('expires_at', '<=', BaseModel::dbTime($now));
        })->delete();

        $cutoff = BaseModel::dbTime($now->copy()->subDays($settings->int('metadata_retention_days')));
        $jobs = DB::table('jobs')->whereIn('state', ['completed', 'failed', 'cancelled', 'expired'])->where('created_at', '<', $cutoff)->delete();
        $usage = DB::table('usage_events')->where('created_at', '<', $cutoff)->delete();
        $samples = DB::table('health_samples')->where('sampled_at', '<', BaseModel::dbTime($now->copy()->subDays(30)))->delete();
        $counters = DB::table('quota_counters')->where('window_start', '<', $now->copy()->subDays(2)->format('Y-m-d'))->delete();
        $nonces = DB::table('assertion_nonces')->where('expires_at', '<', $now)->delete();
        $idem = DB::table('mgmt_idempotency')->where('created_at', '<', $now->copy()->subDay())->delete();

        $this->line(json_encode(compact('payloads', 'jobs', 'usage', 'samples', 'counters', 'nonces', 'idem')));

        return self::SUCCESS;
    }
}
