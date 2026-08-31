<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChecklistTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama_template' => $this->nama_template,
            'deskripsi' => $this->deskripsi,
            'items_count' => $this->items_count ?? ($this->relationLoaded('items') ? $this->items->count() : $this->items()->count()),
            'items' => $this->relationLoaded('items') ? $this->items->map(fn($item) => [
                'id' => $item->id,
                'checklist_template_id' => $item->checklist_template_id,
                'nama_item' => $item->nama_item,
                'deskripsi' => $item->deskripsi,
            ]) : [],
            'rooms_count' => $this->rooms_count ?? 0,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
