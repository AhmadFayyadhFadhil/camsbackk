<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use App\Enums\RoleEnum;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'username',
        'email',
        'password',
        'full_name',
        'phone',
        'foto_profile',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    public function roles(): HasManyThrough
    {
        return $this->hasManyThrough(
            Role::class,
            UserRole::class,
            'user_id',     // Foreign key on user_roles
            'id',          // Foreign key on roles
            'id',          // Local key on users
            'role_id'      // Local key on user_roles
        );
    }

    public function hasRole(string|RoleEnum $role): bool
    {
        $roleName = $role instanceof RoleEnum ? $role->value : $role;
        return $this->roles()->where('name', $roleName)->exists();
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'user_id');
    }

    public function csAssignments(): HasMany
    {
        return $this->hasMany(CsAssignment::class, 'cs_user_id');
    }

    public function activeAssignment(): HasOne
    {
        $today = today()->toDateString();
        return $this->hasOne(CsAssignment::class, 'cs_user_id')
            ->where('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('tanggal_selesai')
                      ->orWhere('tanggal_selesai', '>=', $today);
            });
    }

    public function picHistories(): HasMany
    {
        return $this->hasMany(RoomPicHistory::class, 'user_id');
    }

    public function roomsAsPic(): HasMany
    {
        return $this->hasMany(Room::class, 'pic_user_id');
    }

    public function adhocTasksAssigned(): HasMany
    {
        return $this->hasMany(AdhocTask::class, 'cs_user_id');
    }

    public function adhocTasksCreated(): HasMany
    {
        return $this->hasMany(AdhocTask::class, 'created_by');
    }
}
