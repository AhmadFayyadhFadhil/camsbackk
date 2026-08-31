<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoomAssetAuditItem extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'room_asset_audit_id',
        'room_asset_id',
        'nama_aset_snapshot',
        'kode_aset_snapshot',
        'jumlah_expected',
        'jumlah_actual',
        'kondisi', // good, damaged, missing
        'foto_bukti',
        'catatan',
    ];

    protected $casts = [
        'jumlah_expected' => 'integer',
        'jumlah_actual' => 'integer',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(RoomAssetAudit::class, 'room_asset_audit_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(RoomAsset::class, 'room_asset_id');
    }
}

