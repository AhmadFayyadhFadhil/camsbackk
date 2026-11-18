<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionRedirect extends Model
{
    use HasUuids;

    public const REASON_VERIFIER_OFF = 'verifier_off';
    public const REASON_SLA_OVERDUE = 'sla_overdue';
    public const REASON_VERIFIER_UNAVAILABLE = 'verifier_unavailable';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'submission_id',
        'from_user_id',
        'to_user_id',
        'reason',
        'redirected_by',
        'note',
        'redirected_at',
    ];

    protected $casts = [
        'redirected_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(ChecklistSubmission::class, 'submission_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redirected_by');
    }
}
