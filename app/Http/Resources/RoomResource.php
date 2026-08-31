<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'nama_gedung' => $this->building?->nama_gedung,
            'kode_ruangan' => $this->kode_ruangan,
            'nama_ruangan' => $this->nama_ruangan,
            'lantai' => $this->lantai,
            'pic_user_id' => $this->pic_user_id,
            'pic_name' => $this->pic?->full_name,
            'checklist_template_id' => $this->checklist_template_id,
            'template_name' => $this->template?->nama_template,
            'template' => $this->template ? [
                'id' => $this->template->id,
                'nama_template' => $this->template->nama_template,
            ] : null,
            'is_active' => (bool)$this->is_active,
            
            // Asset Audit Scheduling
            'asset_audit_interval' => $this->asset_audit_interval ?? 'bimonthly',
            'asset_audit_interval_days' => $this->asset_audit_interval_days ?? 60,
            'last_asset_audit_at' => $this->last_asset_audit_at?->toIso8601String(),
            'next_asset_audit_due' => $this->next_asset_audit_due ? $this->next_asset_audit_due->toDateString() : null,

            // Frontend expected parameters
            'name' => $this->nama_ruangan,
            'code' => $this->kode_ruangan,
            'floor' => $this->lantai,
            'building' => $this->building ? [
                'id' => $this->building->id,
                'name' => $this->building->nama_gedung,
                'code' => $this->building->kode_gedung,
                'shifts' => ShiftResource::collection($this->building->shifts),
            ] : null,
            'active_pic' => $this->pic ? [
                'user_id' => $this->pic->id,
                'user' => [
                    'id' => $this->pic->id,
                    'name' => $this->pic->full_name,
                ]
            ] : null,
            
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
