<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Enums\SubmissionStatusEnum;

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
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function cs(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cs_user_id');
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
        )->withTimestamps();
    }
}
