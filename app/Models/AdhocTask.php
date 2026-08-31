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
        'due_datetime',
        'event_start_time',
        'checklist_items',
        'status', // pending, in_progress, submitted, verified, rejected
        'foto_bukti',
        'foto_bukti_mime',
        'verification_notes',
        'started_at',
        'submitted_at',
        'verified_at',
    ];

    protected $hidden = [
        'foto_bukti',
    ];

    protected $casts = [
        'due_datetime' => 'datetime',
        'event_start_time' => 'datetime',
        'checklist_items' => 'array',
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
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
