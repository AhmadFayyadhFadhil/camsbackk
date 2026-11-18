<?php

namespace App\Http\Resources;

use App\Models\ChecklistTemplateItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChecklistSubmissionResource extends JsonResource
{
    /**
     * Detail SOP item checklist. Item jadwal dibuat dari template berdasarkan nama_item,
     * sehingga SOP dicari dari item template dengan nama sama (prioritas template ruangan).
     */
    private function sopFor(?string $namaItem): ?array
    {
        if (!$namaItem) {
            return null;
        }
        $key = mb_strtolower(trim($namaItem));
        $templateId = $this->task?->room?->checklist_template_id;

        // Cache per request (bukan static) agar perubahan template langsung terbaca di request berikutnya
        $attrs = request()->attributes;
        if (!$attrs->has('sop_map')) {
            $attrs->set('sop_map', ChecklistTemplateItem::query()
                ->whereNotNull('deskripsi')->where('deskripsi', '!=', '')
                ->with('template:id,nama_template')
                ->get(['id', 'checklist_template_id', 'nama_item', 'deskripsi'])
                ->groupBy(fn ($i) => mb_strtolower(trim($i->nama_item))));
        }

        $candidates = $attrs->get('sop_map')->get($key);
        if (!$candidates || $candidates->isEmpty()) {
            return null;
        }
        $pick = $candidates->firstWhere('checklist_template_id', $templateId) ?? $candidates->first();

        return [
            'description' => $pick->deskripsi,
            'template_name' => $pick->template?->nama_template,
        ];
    }

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
            'submission_time' => $this->submitted_at ? $this->submitted_at->setTimezone(new \DateTimeZone(config('app.timezone', 'Asia/Jakarta')))->format('Y-m-d H:i') . ' WIB' : null,
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
                'sop' => $this->sopFor($res->checklistItem?->nama_item),
                'is_done' => (bool)$res->is_done,
                'status' => (bool)$res->is_done,
                'catatan' => $res->catatan,
                'notes' => $res->catatan,
            ]) : null,
            'materials' => $this->relationLoaded('materials') ? $this->materials->map(fn($m) => [
                'id' => $m->id,
                'nama_material' => $m->nama_material,
                'jenis' => $m->jenis,
                'kode_material' => $m->kode_material,
            ]) : [],
            // Foto After opsional (maks. 1 foto untuk input baru). Laporan lama dapat memiliki foto_after_2..4.
            'has_foto_after' => !empty($this->foto_after_1) || !empty($this->foto_after),
            'foto_after_url' => (!empty($this->foto_after_1) || !empty($this->foto_after)) ? url("/api/v1/submissions/{$this->id}/foto-after-1") : null,
            'foto_after_1_url' => (!empty($this->foto_after_1) || !empty($this->foto_after)) ? url("/api/v1/submissions/{$this->id}/foto-after-1") : null,
            'foto_after_2_url' => $this->foto_after_2 ? url("/api/v1/submissions/{$this->id}/foto-after-2") : null,
            'foto_after_3_url' => $this->foto_after_3 ? url("/api/v1/submissions/{$this->id}/foto-after-3") : null,
            'foto_after_4_url' => $this->foto_after_4 ? url("/api/v1/submissions/{$this->id}/foto-after-4") : null,
            'has_multiple_photos' => !empty($this->foto_after_2) || !empty($this->foto_after_3) || !empty($this->foto_after_4),
            'workflow' => app(\App\Services\VerificationWorkflowService::class)->describe($this->resource, $request->user()),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'gps_accuracy' => $this->gps_accuracy,
            'gps_captured_at' => $this->gps_captured_at?->toIso8601String(),
            'verification' => ($this->latestVerification ?? ($this->relationLoaded('verifications') ? $this->verifications->last() : null)) ? [
                'id' => ($this->latestVerification ?? $this->verifications->last())->id,
                'status' => ($this->latestVerification ?? $this->verifications->last())->status,
                'catatan_perbaikan' => ($this->latestVerification ?? $this->verifications->last())->catatan_perbaikan,
                'verified_by_name' => ($this->latestVerification ?? $this->verifications->last())->verifier?->full_name,
                'verified_at' => ($this->latestVerification ?? $this->verifications->last())->verified_at?->toIso8601String(),
                'is_onsite_verified' => ($this->latestVerification ?? $this->verifications->last())->is_onsite_verified,
                'qr_scanned_at' => ($this->latestVerification ?? $this->verifications->last())->qr_scanned_at?->toIso8601String(),
                'foto_inspeksi_url' => ($this->latestVerification ?? $this->verifications->last())->foto_inspeksi_path ? url("/api/v1/verifications/" . ($this->latestVerification ?? $this->verifications->last())->id . "/foto-inspeksi") : null,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}