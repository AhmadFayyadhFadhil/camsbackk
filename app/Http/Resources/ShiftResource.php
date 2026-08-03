<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kode_shift' => $this->kode_shift,
            'nama_shift' => $this->nama_shift,
            'jam_mulai' => substr($this->jam_mulai, 0, 5),
            'jam_selesai' => substr($this->jam_selesai, 0, 5),
            'is_overnight' => (bool)$this->is_overnight,
            'is_active' => (bool)$this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Frontend-compatible aliases
            'name' => $this->nama_shift,
            'start_time' => substr($this->jam_mulai, 0, 5),
            'end_time' => substr($this->jam_selesai, 0, 5),
        ];
    }
}
