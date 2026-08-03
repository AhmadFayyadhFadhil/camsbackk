<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    protected $fillable = [
        'kode_shift',
        'nama_shift',
        'jam_mulai',
        'jam_selesai',
        'is_overnight',
        'is_active',
    ];

    protected $casts = [
        'is_overnight' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function buildings(): BelongsToMany
    {
        return $this->belongsToMany(Building::class, 'building_shifts', 'shift_id', 'building_id')
            ->using(BuildingShift::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class, 'shift_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'shift_id');
    }

    public function csAssignments(): HasMany
    {
        return $this->hasMany(CsAssignment::class, 'shift_id');
    }
}
