<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_id' => $this->room_id,
            'room' => $this->room ? [
                'id' => $this->room->id,
                'name' => $this->room->nama_ruangan,
                'code' => $this->room->kode_ruangan,
                'building' => $this->room->building ? [
                    'id' => $this->room->building->id,
                    'name' => $this->room->building->nama_gedung,
                    'code' => $this->room->building->kode_gedung,
                ] : null
            ] : null,
            'nama_ruangan' => $this->room?->nama_ruangan,
            'kode_ruangan' => $this->room?->kode_ruangan,
            'checklist_item_id' => $this->checklist_item_id,
            'nama_item' => $this->checklistItem?->nama_item,
            'shift_id' => $this->shift_id,
            'kode_shift' => $this->shift?->kode_shift,
            'nama_shift' => $this->shift?->nama_shift,
            'frekuensi' => $this->frekuensi->value,
            'frequency' => $this->frekuensi->value,
            'hari_minggu' => $this->hari_minggu,
            'day_of_week' => $this->hari_minggu !== null ? [
                0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
                4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'
            ][$this->hari_minggu] : null,
            'tanggal_bulan' => $this->tanggal_bulan,
            'day_of_month' => $this->tanggal_bulan,
            'target_jam_mulai' => $this->target_jam_mulai ? substr($this->target_jam_mulai, 0, 5) : null,
            'target_jam_selesai' => $this->target_jam_selesai ? substr($this->target_jam_selesai, 0, 5) : null,
            'estimasi_durasi_menit' => $this->estimasi_durasi_menit ?? 30,
            'urutan' => $this->urutan ?? 1,
            'is_active' => (bool)$this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
