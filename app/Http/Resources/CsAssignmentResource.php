<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CsAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cs_user_id' => $this->cs_user_id,
            'cs_name' => $this->cs?->full_name,
            'building_id' => $this->building_id,
            'nama_gedung' => $this->building?->nama_gedung,
            'shift_id' => $this->shift_id,
            'kode_shift' => $this->shift?->kode_shift,
            'nama_shift' => $this->shift?->nama_shift,
            'tanggal_mulai' => $this->tanggal_mulai?->toDateString(),
            'tanggal_selesai' => $this->tanggal_selesai?->toDateString(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
