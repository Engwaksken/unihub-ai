<?php

namespace Unihub\CentralAi;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Local record linking an app user to a gateway job (PRD §09: "Store gateway job ID
 * with local user ownership"). Flutter only ever sees this record's id, never the
 * gateway job id or key. Output is stored encrypted and only until it is delivered.
 *
 * @property string $id
 * @property int|string $user_id
 * @property string $task
 * @property string $idempotency_key
 * @property string|null $gateway_job_id
 * @property string $status  pending|queued|running|completed|failed|expired|cancelled
 * @property string|null $output
 * @property string|null $error_code
 * @property \Illuminate\Support\Carbon|null $next_poll_at
 * @property \Illuminate\Support\Carbon|null $retry_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class AiRequest extends Model
{
    use HasUuids;

    protected $table = 'ai_requests';

    protected $guarded = [];

    protected $hidden = ['gateway_job_id', 'idempotency_key', 'input'];

    public const TERMINAL = ['completed', 'failed', 'expired', 'cancelled'];

    protected function casts(): array
    {
        return [
            'input' => 'encrypted',
            'output' => 'encrypted',
            'next_poll_at' => 'datetime',
            'retry_at' => 'datetime',
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
