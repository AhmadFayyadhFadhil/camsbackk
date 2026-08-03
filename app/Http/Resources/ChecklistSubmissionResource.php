<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChecklistSubmissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'task' => $this->task ? new TaskResource($this->task) : null,
            'cs_user_id' => $this->cs_user_id,
            'cs_name' => $this->cs?->full_name,
            'user' => $this->cs ? [
                'id' => $this->cs->id,
                'name' => $this->cs->full_name,
            ] : null,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'submission_time' => $this->submitted_at?->toDateTimeString(),
            'resubmit_count' => (int)$this->resubmit_count,
            'scan_token_used' => $this->scan_token_used,
            'catatan_cs' => $this->catatan_cs,
            'notes' => $this->catatan_cs,
            'status' => $this->status->value,
            'results' => $this->relationLoaded('results') ? $this->results->map(fn($res) => [
                'id' => $res->id,
                'checklist_item_id' => $res->checklist_item_id,
                'checklist_item' => [
                    'id' => $res->checklist_item_id,
                    'name' => $res->checklistItem?->nama_item,
                ],
                'nama_item' => $res->checklistItem?->nama_item,
                'is_done' => (bool)$res->is_done,
                'status' => (bool)$res->is_done,
                'catatan' => $res->catatan,
                'notes' => $res->catatan,
            ]) : null,
            'foto_after_1_url' => url("/api/v1/submissions/{$this->id}/foto-after-1"),
            'foto_after_2_url' => url("/api/v1/submissions/{$this->id}/foto-after-2"),
            'foto_after_3_url' => url("/api/v1/submissions/{$this->id}/foto-after-3"),
            'foto_after_4_url' => url("/api/v1/submissions/{$this->id}/foto-after-4"),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'gps_accuracy' => $this->gps_accuracy,
            'gps_captured_at' => $this->gps_captured_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
