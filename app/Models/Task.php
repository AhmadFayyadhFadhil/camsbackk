<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Enums\TaskStatusEnum;

class Task extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'schedule_id',
        'room_id',
        'cs_user_id',
        'shift_id',
        'tanggal_task',
        'target_jam_mulai',
        'target_jam_selesai',
        'status',
        'due_datetime',
    ];

    protected $casts = [
        'tanggal_task' => 'date:Y-m-d',
        'status' => TaskStatusEnum::class,
        'due_datetime' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class, 'schedule_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function cs(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cs_user_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function submission(): HasOne
    {
        return $this->hasOne(ChecklistSubmission::class, 'task_id');
    }
}
