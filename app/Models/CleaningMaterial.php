<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CleaningMaterial extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'nama_material',
        'jenis', // chemical, tool
        'kode_material',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function submissions(): BelongsToMany
    {
        return $this->belongsToMany(
            ChecklistSubmission::class,
            'submission_materials',
            'cleaning_material_id',
            'submission_id'
        )->withTimestamps();
    }
}
