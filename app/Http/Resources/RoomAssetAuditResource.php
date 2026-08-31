<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomAssetAuditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'room_name' => $this->room?->nama_ruangan,
            'room_code' => $this->room?->kode_ruangan,
            'building_name' => $this->room?->building?->nama_gedung,
            'auditor_id' => $this->auditor_id,
            'auditor_name' => $this->auditor?->full_name,
            'verified_by' => $this->verified_by,
            'verifier_name' => $this->verifier?->full_name,
            'periode' => $this->periode,
            'audit_date' => $this->audit_date?->toDateString(),
            'status' => $this->status, // submitted, approved, rejected
            'total_expected' => (int)$this->total_expected,
            'total_actual' => (int)$this->total_actual,
            'has_discrepancy' => (bool)$this->has_discrepancy,
            'notes' => $this->notes,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'verification_notes' => $this->verification_notes,
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'room_asset_id' => $item->room_asset_id,
                        'nama_aset' => $item->nama_aset_snapshot ?: $item->asset?->nama_aset,
                        'kode_aset' => $item->kode_aset_snapshot ?: $item->asset?->kode_aset,
                        'jumlah_expected' => (int)$item->jumlah_expected,
                        'jumlah_actual' => (int)$item->jumlah_actual,
                        'kondisi' => $item->kondisi, // good, damaged, missing
                        'foto_bukti_url' => $item->foto_bukti ? url("api/v1/room-asset-audits/{$this->id}/items/{$item->id}/foto") : null,
                        'catatan' => $item->catatan,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
