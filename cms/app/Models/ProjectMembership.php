<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $user_id
 * @property string $gateway_project_uuid
 * @property Role $role
 */
class ProjectMembership extends Model
{
    protected $fillable = ['gateway_project_uuid', 'role'];

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
