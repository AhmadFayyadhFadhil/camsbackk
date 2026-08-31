<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Room extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'building_id',
        'kode_ruangan',
        'nama_ruangan',
        'lantai',
        'pic_user_id',
        'checklist_template_id',
        'qr_code_token',
        'qr_code_image',
        'is_active',
    ];

    protected $hidden = [
        'qr_code_image', // Hide blob from JSON response default
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'building_id');
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(RoomAsset::class, 'room_id');
    }

    public function picHistories(): HasMany
    {
        return $this->hasMany(RoomPicHistory::class, 'room_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'room_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'room_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(Finding::class, 'room_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (Room $room) {
            // Cascade soft-delete schedules for this room
            foreach ($room->schedules as $schedule) {
                $schedule->update(['is_active' => false]);
                $schedule->delete();
            }

            // Cancel pending tasks
            $room->tasks()->where('status', \App\Enums\TaskStatusEnum::PENDING)->delete();
        });
    }
}
