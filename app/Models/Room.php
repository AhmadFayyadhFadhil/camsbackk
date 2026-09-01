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
        'latitude',
        'longitude',
        'radius_meter',
        'asset_audit_interval',
        'asset_audit_interval_days',
        'last_asset_audit_at',
        'next_asset_audit_due',
    ];

    protected $hidden = [
        'qr_code_image', // Hide blob from JSON response default
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meter' => 'integer',
        'asset_audit_interval_days' => 'integer',
        'last_asset_audit_at' => 'datetime',
        'next_asset_audit_due' => 'date',
    ];

    /**
     * Mendapatkan koordinat efektif (jika ruangan memiliki koordinat sendiri, gunakan ruangan; jika tidak, inherit dari gedung).
     */
    public function getEffectiveGeofence(): array
    {
        if ($this->latitude !== null && $this->longitude !== null) {
            return [
                'type' => 'room',
                'target_name' => $this->nama_ruangan,
                'latitude' => (float)$this->latitude,
                'longitude' => (float)$this->longitude,
                'radius_meter' => (int)($this->radius_meter ?: 30),
            ];
        }

        $building = $this->building;
        if ($building && $building->latitude !== null && $building->longitude !== null) {
            return [
                'type' => 'building',
                'target_name' => $building->nama_gedung,
                'latitude' => (float)$building->latitude,
                'longitude' => (float)$building->longitude,
                'radius_meter' => (int)($building->radius_meter ?: 250),
            ];
        }

        return [
            'type' => 'none',
            'target_name' => null,
            'latitude' => null,
            'longitude' => null,
            'radius_meter' => 250,
        ];
    }

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

    public function assetAudits(): HasMany
    {
        return $this->hasMany(RoomAssetAudit::class, 'room_id');
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
