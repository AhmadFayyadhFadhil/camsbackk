<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdhocTask extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'created_by',
        'cs_user_id',
        'room_id',
        'judul',
        'deskripsi',
        'priority', // low, medium, high
        'task_type', // immediate, scheduled_event
        'requires_cleanup',
        'due_datetime',
        'event_start_time',
        'checklist_items',
        'status', // pending, in_progress, submitted, verified, rejected
        'stage', // pending, setup_in_progress, setup_submitted, cleanup_in_progress, completed
        'foto_bukti',
        'foto_bukti_mime',
        'foto_bukti_cleanup',
        'foto_bukti_cleanup_mime',
        'verification_notes',
        'started_at',
        'submitted_at',
        'setup_submitted_at',
        'cleanup_submitted_at',
        'verified_at',
    ];

    protected $hidden = [
        'foto_bukti',
        'foto_bukti_cleanup',
    ];

    protected $casts = [
        'requires_cleanup' => 'boolean',
        'due_datetime' => 'datetime',
        'event_start_time' => 'datetime',
        'checklist_items' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'setup_submitted_at' => 'datetime',
        'cleanup_submitted_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cs(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cs_user_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
