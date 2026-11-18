<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Enums\SubmissionStatusEnum;
use App\Enums\VerificationStageEnum;

class ChecklistSubmission extends Model
{
    use HasUuids;

    protected $table = 'checklist_submissions';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'task_id',
        'cs_user_id',
        'submitted_at',
        'resubmit_count',
        'scan_token_used',
        'catatan_cs',
        'status',
        'foto_before',
        'foto_before_mime',
        'foto_after_3',
        'foto_after_3_mime',
        'foto_after_4',
        'foto_after_4_mime',
        'foto_after',
        'foto_after_mime',
        'foto_after_1',
        'foto_after_1_mime',
        'foto_after_2',
        'foto_after_2_mime',
        'latitude',
        'longitude',
        'gps_accuracy',
        'gps_captured_at',
        'verification_stage',
        'verifier_id',
        'verification_deadline_at',
        'verified_at',
        'verified_by',
        'redirected_at',
        'redirect_reason',
        'final_approved_at',
        'final_approved_by',
    ];

    protected $hidden = [
        'foto_before',
        'foto_after_3',
        'foto_after_4',
        'foto_after',
        'foto_after_1',
        'foto_after_2',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'status' => SubmissionStatusEnum::class,
        'resubmit_count' => 'integer',
        'gps_captured_at' => 'datetime',
        'verification_deadline_at' => 'datetime',
        'verified_at' => 'datetime',
        'redirected_at' => 'datetime',
        'final_approved_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function cs(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cs_user_id');
    }

    /** Penangani tahap verifikasi saat ini (Pak Kusnan, atau Pak Winaryo bila dialihkan). */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verifier_id');
    }

    public function verifiedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function finalApprover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_approved_by');
    }

    public function redirects(): HasMany
    {
        return $this->hasMany(SubmissionRedirect::class, 'submission_id');
    }

    /**
     * Tahap workflow efektif. Data lama (verification_stage null) diturunkan dari status:
     * submitted => menunggu verifikasi, approved => selesai, rejected => dikembalikan.
     */
    public function effectiveStage(): VerificationStageEnum
    {
        if ($this->verification_stage) {
            $stage = VerificationStageEnum::tryFrom($this->verification_stage);
            if ($stage) {
                return $stage;
            }
        }

        return match ($this->status) {
            SubmissionStatusEnum::APPROVED => VerificationStageEnum::COMPLETED,
            SubmissionStatusEnum::REJECTED => VerificationStageEnum::REJECTED,
            default => VerificationStageEnum::PENDING_VERIFICATION,
        };
    }

    public function results(): HasMany
    {
        return $this->hasMany(ChecklistResult::class, 'submission_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class, 'submission_id');
    }

    public function latestVerification(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Verification::class, 'submission_id')->latestOfMany();
    }

    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(
            CleaningMaterial::class,
            'submission_materials',
            'submission_id',
            'cleaning_material_id'
        )->using(SubmissionMaterial::class)->withTimestamps();
    }
}
