<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SlaParameter extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'nama_parameter',
        'deskripsi',
        'tipe_penilaian', // scale_1_5, yes_no
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function ratings(): HasMany
    {
        return $this->hasMany(VerificationSlaRating::class, 'sla_parameter_id');
    }
}
