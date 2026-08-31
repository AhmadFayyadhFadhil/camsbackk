<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'name' => $this->full_name,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'foto_profile' => $this->foto_profile ? url('api/v1/auth/avatar/' . $this->id) . '?v=' . ($this->updated_at?->timestamp ?? time()) : null,
            'avatar_url' => $this->foto_profile ? url('api/v1/auth/avatar/' . $this->id) . '?v=' . ($this->updated_at?->timestamp ?? time()) : null,
            'is_active' => (bool)$this->is_active,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $this->roles->map(fn($r) => $r->name === 'cs' ? 'cleaning_service' : $r->name)->unique()->values(),
            'is_on_shift' => (function() {
                if ($this->relationLoaded('activeAssignment') && $this->activeAssignment && $this->activeAssignment->shift) {
                    return \App\Helpers\ShiftValidatorHelper::isWithinShift($this->activeAssignment->shift);
                }
                return false;
            })(),
            'shift_label' => (function() {
                if ($this->relationLoaded('activeAssignment') && $this->activeAssignment && $this->activeAssignment->shift) {
                    $shift = $this->activeAssignment->shift;
                    return $shift->nama_shift . ' (' . substr($shift->jam_mulai, 0, 5) . ' - ' . substr($shift->jam_selesai, 0, 5) . ')';
                }
                return null;
            })(),
            'active_assignment' => $this->relationLoaded('activeAssignment') && $this->activeAssignment ? [
                'id' => $this->activeAssignment->id,
                'building_id' => $this->activeAssignment->building_id,
                'building_name' => $this->activeAssignment->building?->nama_gedung,
                'shift_id' => $this->activeAssignment->shift_id,
                'shift_code' => $this->activeAssignment->shift?->kode_shift,
                'tanggal_mulai' => $this->activeAssignment->tanggal_mulai?->toDateString(),
                'tanggal_selesai' => $this->activeAssignment->tanggal_selesai?->toDateString(),
            ] : null,
            'pic_rooms' => $this->when($this->relationLoaded('picHistories'), function() {
                return $this->picHistories
                    ->filter(function ($history) {
                        return is_null($history->tanggal_selesai) || $history->tanggal_selesai->gte(today());
                    })
                    ->map(fn($history) => [
                        'room_id' => $history->room_id,
                        'nama_ruangan' => $history->room?->nama_ruangan,
                        'kode_ruangan' => $history->room?->kode_ruangan,
                        'tanggal_mulai' => $history->tanggal_mulai?->toDateString(),
                        'tanggal_selesai' => $history->tanggal_selesai?->toDateString(),
                    ])->values();
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
