<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $user_id
 * @property Role $role
 */
class UserRole extends Model
{
    protected $fillable = ['role'];

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
