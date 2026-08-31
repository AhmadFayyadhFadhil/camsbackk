<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'kode_gedung',
        'nama_gedung',
        'alamat',
        'latitude',
        'longitude',
        'asset_audit_interval',
        'asset_audit_interval_days',
        'last_asset_audit_at',
        'next_asset_audit_due',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
        'asset_audit_interval_days' => 'integer',
        'last_asset_audit_at' => 'datetime',
        'next_asset_audit_due' => 'date',
    ];

    protected $appends = [
        'audit_status',
    ];

    public function getAuditStatusAttribute(): string
    {
        if (!$this->next_asset_audit_due) {
            return 'pending';
        }
        $due = \Carbon\Carbon::parse($this->next_asset_audit_due);
        $today = \Carbon\Carbon::today();
        if ($due->isPast() && !$due->isToday()) {
            return 'overdue';
        }
        if ($due->diffInDays($today) <= 7) {
            return 'due_soon';
        }
        return 'up_to_date';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(RoomAssetAudit::class, 'building_id');
    }

    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'building_shifts', 'building_id', 'shift_id')
            ->using(BuildingShift::class);
    }

    public function buildingShifts(): HasMany
    {
        return $this->hasMany(BuildingShift::class, 'building_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class, 'building_id');
    }

    public function csAssignments(): HasMany
    {
        return $this->hasMany(CsAssignment::class, 'building_id');
    }

    protected static function booted(): void
    {
        static::deleting(function (Building $building) {
            // Cascade soft-delete all child rooms
            foreach ($building->rooms as $room) {
                $room->update(['is_active' => false]);
                $room->delete();
            }

            // Deactivate CS assignments for this building
            $building->csAssignments()->update([
                'tanggal_selesai' => now()->toDateString(),
            ]);
        });
    }
}
