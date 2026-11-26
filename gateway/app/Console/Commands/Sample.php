<?php

namespace App\Console\Commands;

use App\Services\Telemetry;
use Illuminate\Console\Command;

class Sample extends Command
{
    protected $signature = 'gateway:sample';

    protected $description = 'Record a health sample and evaluate alert rules';

    public function handle(Telemetry $telemetry): int
    {
        $s = $telemetry->sample();
        $this->line(sprintf('sampled ram=%s%% disk=%s%% queue=%d ollama=%s', $s->ram_used_pct ?? '?', $s->disk_used_pct ?? '?', $s->queue_depth, $s->ollama_status));

        return self::SUCCESS;
    }
}
