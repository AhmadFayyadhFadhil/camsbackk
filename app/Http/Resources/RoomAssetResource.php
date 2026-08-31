<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'room_name' => $this->room?->nama_ruangan,
            'room_code' => $this->room?->kode_ruangan,
            'nama_aset' => $this->nama_aset,
            'kode_aset' => $this->kode_aset,
            'merk' => $this->merk,
            'status' => $this->status, // active, damaged, repaired
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
