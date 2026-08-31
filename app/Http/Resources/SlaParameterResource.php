<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SlaParameterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama_parameter' => $this->nama_parameter,
            'deskripsi' => $this->deskripsi,
            'tipe_penilaian' => $this->tipe_penilaian, // scale_1_5, yes_no
            'is_active' => (bool)$this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
