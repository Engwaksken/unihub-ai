<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $job_id
 * @property string $key_id
 * @property string $input_ct
 * @property string|null $output_ct
 */
class JobPayload extends Model
{
    protected $primaryKey = 'job_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $dateFormat = BaseModel::DATE_FORMAT;

    protected $guarded = [];
}
