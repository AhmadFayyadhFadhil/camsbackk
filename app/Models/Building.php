<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'kode_gedung',
        'nama_gedung',
        'alamat',
        'latitude',
        'longitude',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
}
