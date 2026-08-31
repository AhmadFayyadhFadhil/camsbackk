<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdhocTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_by' => $this->created_by,
            'creator_name' => $this->creator?->full_name,
            'cs_user_id' => $this->cs_user_id,
            'cs_name' => $this->cs?->full_name,
            'room_id' => $this->room_id,
            'room_name' => $this->room?->nama_ruangan,
            'room_code' => $this->room?->kode_ruangan,
            'building_name' => $this->room?->building?->nama_gedung,
            'judul' => $this->judul,
            'deskripsi' => $this->deskripsi,
            'priority' => $this->priority,
            'task_type' => $this->task_type ?? 'immediate',
            'due_datetime' => $this->due_datetime?->format('Y-m-d H:i'),
            'event_start_time' => $this->event_start_time?->format('Y-m-d H:i'),
            'checklist_items' => $this->checklist_items ?? [],
            'status' => $this->status,
            'has_foto_bukti' => !empty($this->foto_bukti),
            'foto_bukti_url' => !empty($this->foto_bukti) ? url("/api/v1/adhoc-tasks/{$this->id}/foto-bukti") : null,
            'verification_notes' => $this->verification_notes,
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
