<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoomAssetAudit extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'room_id',
        'auditor_id',
        'verified_by',
        'periode',
        'audit_date',
        'status', // submitted, approved, rejected
        'total_expected',
        'total_actual',
        'has_discrepancy',
        'notes',
        'verified_at',
        'verification_notes',
    ];

    protected $casts = [
        'audit_date' => 'date',
        'total_expected' => 'integer',
        'total_actual' => 'integer',
        'has_discrepancy' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RoomAssetAuditItem::class, 'room_asset_audit_id');
    }
}

