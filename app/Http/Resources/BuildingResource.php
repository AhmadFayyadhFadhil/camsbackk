<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BuildingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode_gedung' => $this->kode_gedung,
            'code' => $this->kode_gedung,
            'nama_gedung' => $this->nama_gedung,
            'name' => $this->nama_gedung,
            'alamat' => $this->alamat,
            'description' => $this->alamat,
            'latitude' => $this->latitude !== null ? (float)$this->latitude : null,
            'longitude' => $this->longitude !== null ? (float)$this->longitude : null,
            'radius_meter' => (int)($this->radius_meter ?? 250),
            'is_active' => (bool)$this->is_active,
            'asset_audit_interval' => $this->asset_audit_interval ?? 'bimonthly',
            'asset_audit_interval_days' => (int)($this->asset_audit_interval_days ?? 60),
            'last_asset_audit_at' => $this->last_asset_audit_at?->toIso8601String(),
            'next_asset_audit_due' => $this->next_asset_audit_due?->toDateString(),
            'audit_status' => $this->audit_status,
            'created_by' => $this->created_by,
            'rooms_count' => $this->whenCounted('rooms'),
            'shifts' => ShiftResource::collection($this->whenLoaded('shifts')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
