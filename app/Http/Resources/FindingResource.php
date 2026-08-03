<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'room_name' => $this->room?->nama_ruangan,
            'room_code' => $this->room?->kode_ruangan,
            'finding_category_id' => $this->finding_category_id,
            'category_name' => $this->category?->nama_kategori ?? 'Lainnya',
            'category_code' => $this->category?->kode_kategori ?? 'OTHER',
            'reported_by' => $this->reported_by,
            'reporter_name' => $this->reporter?->full_name,
            'pic_id' => $this->room?->pic_user_id,
            'pic_name' => $this->room?->pic?->full_name,
            'deskripsi' => $this->deskripsi,
            'prioritas' => $this->prioritas?->value,
            'status' => $this->status?->value,
            'deadline_perbaikan' => $this->deadline_perbaikan?->toDateString(),
            'resolved_at' => $this->resolved_at?->toDateTimeString(),
            'assigned_to' => $this->assigned_to,
            'assigned_to_external' => $this->assigned_to_external,
            'assignee_name' => $this->assignee?->full_name,
            'assigned_at' => $this->assigned_at?->toDateTimeString(),
            'response_time_minutes' => $this->response_time_minutes,
            'is_overdue' => $this->deadline_perbaikan ? ($this->deadline_perbaikan->isPast() && $this->status?->value !== 'resolved') : false,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            'room' => $this->room ? [
                'id' => $this->room->id,
                'name' => $this->room->nama_ruangan,
                'code' => $this->room->kode_ruangan,
                'pic_user_id' => $this->room->pic_user_id,
                'building' => $this->room->building ? [
                    'id' => $this->room->building->id,
                    'name' => $this->room->building->nama_gedung,
                ] : null,
            ] : null,
        ];
    }
}
