<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_id' => $this->schedule_id,
            'cs_user_id' => $this->cs_user_id,
            'cs_name' => $this->cs?->full_name ?: $this->cs?->username ?: ($this->cs_user_id ? 'CS' : null),
            'room_id' => $this->room_id,
            'nama_ruangan' => $this->room?->nama_ruangan,
            'kode_ruangan' => $this->room?->kode_ruangan,
            'shift_id' => $this->shift_id,
            'kode_shift' => $this->shift?->kode_shift,
            'nama_shift' => $this->shift?->nama_shift,
            'tanggal_task' => $this->tanggal_task?->toDateString(),
            'task_date' => $this->tanggal_task?->toDateString(),
            'target_jam_mulai' => $this->target_jam_mulai ? substr($this->target_jam_mulai, 0, 5) : ($this->schedule?->target_jam_mulai ? substr($this->schedule->target_jam_mulai, 0, 5) : null),
            'target_jam_selesai' => $this->target_jam_selesai ? substr($this->target_jam_selesai, 0, 5) : ($this->schedule?->target_jam_selesai ? substr($this->schedule->target_jam_selesai, 0, 5) : null),
            'status' => $this->status->value,
            'due_datetime' => $this->due_datetime?->toIso8601String(),
            'items_count' => $this->items_count ?? 1,
            'item_names' => $this->checklist_item_names ?? ($this->schedule?->checklistItem ? [$this->schedule->checklistItem->nama_item] : []),
            
            // Nested structures expected by frontend (e.g. CsTasks.jsx, Verifications.jsx)
            'room' => $this->room ? [
                'id' => $this->room->id,
                'name' => $this->room->nama_ruangan,
                'code' => $this->room->kode_ruangan,
                'qr_code_token' => $this->room->qr_code_token,
                'building' => $this->room->building ? [
                    'id' => $this->room->building->id,
                    'name' => $this->room->building->nama_gedung,
                    'code' => $this->room->building->kode_gedung,
                ] : null,
            ] : null,
            'shift' => $this->shift ? [
                'id' => $this->shift->id,
                'name' => $this->shift->nama_shift,
                'code' => $this->shift->kode_shift,
                'start_time' => $this->shift->jam_mulai ? substr($this->shift->jam_mulai, 0, 5) : null,
                'end_time' => $this->shift->jam_selesai ? substr($this->shift->jam_selesai, 0, 5) : null,
            ] : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
