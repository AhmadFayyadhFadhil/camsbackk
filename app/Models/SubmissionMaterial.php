<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SubmissionMaterial extends Pivot
{
    use HasUuids;

    protected $table = 'submission_materials';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'submission_id',
        'cleaning_material_id',
    ];
}
