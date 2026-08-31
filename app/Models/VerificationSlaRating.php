<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VerificationSlaRating extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'verification_id',
        'sla_parameter_id',
        'nilai',
    ];

    public function verification(): BelongsTo
    {
        return $this->belongsTo(Verification::class, 'verification_id');
    }

    public function parameter(): BelongsTo
    {
        return $this->belongsTo(SlaParameter::class, 'sla_parameter_id');
    }
}
