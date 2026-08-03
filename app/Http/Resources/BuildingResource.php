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
            'is_active' => (bool)$this->is_active,
            'created_by' => $this->created_by,
            'rooms_count' => $this->whenCounted('rooms'),
            'shifts' => ShiftResource::collection($this->whenLoaded('shifts')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
