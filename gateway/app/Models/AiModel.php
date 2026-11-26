<?php

namespace App\Models;

use Carbon\CarbonImmutable;

/**
 * @property string $id
 * @property string $tag
 * @property string|null $digest
 * @property string|null $licence
 * @property int|null $size_bytes
 * @property bool $installed
 * @property bool $enabled
 * @property CarbonImmutable|null $checked_at
 */
class AiModel extends BaseModel
{
    protected $table = 'models';

    protected $fillable = ['tag', 'digest', 'licence', 'size_bytes', 'installed', 'enabled', 'checked_at'];

    protected function casts(): array
    {
        return [
            'installed' => 'boolean',
            'enabled' => 'boolean',
            'size_bytes' => 'integer',
            'checked_at' => 'immutable_datetime',
        ];
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'tag' => $this->tag,
            'digest' => $this->digest,
            'licence' => $this->licence,
            'size_bytes' => $this->size_bytes,
            'installed' => $this->installed,
            'enabled' => $this->enabled,
            'checked_at' => self::iso($this->checked_at),
        ];
    }
}
