<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChecklistTemplateResource;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ChecklistTemplateController extends Controller
{
    use ApiResponse;

    /**
     * GET /checklist-templates (admin, supervisor)
     */
    public function index(Request $request)
    {
        $query = ChecklistTemplate::query()->with('items')->withCount(['items', 'rooms']);

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where('nama_template', 'like', "%{$search}%")
                  ->orWhere('deskripsi', 'like', "%{$search}%");
        }

        $perPage = $request->get('per_page', 20);
        $templates = $query->paginate($perPage);

        return $this->paginated(
            ChecklistTemplateResource::collection($templates),
            'Daftar template checklist berhasil diambil.'
        );
    }

    /**
     * POST /checklist-templates (admin, supervisor)
     */
    public function store(Request $request)
    {
        $request->validate([
            'nama_template' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.nama_item' => ['required_with:items', 'string', 'max:255'],
            'items.*.deskripsi' => ['nullable', 'string'],
            'items.*.frekuensi' => ['nullable', 'in:harian,mingguan,bulanan'],
            'items.*.hari_minggu' => ['nullable', 'integer', 'between:0,6'],
            'items.*.tanggal_bulan' => ['nullable', 'integer', 'between:1,31'],
        ]);

        return DB::transaction(function () use ($request) {
            $template = ChecklistTemplate::create([
                'id' => (string) Str::uuid(),
                'nama_template' => $request->nama_template,
                'deskripsi' => $request->deskripsi,
            ]);

            if ($request->has('items') && is_array($request->items)) {
                foreach ($request->items as $itemData) {
                    ChecklistTemplateItem::create([
                        'id' => (string) Str::uuid(),
                        'checklist_template_id' => $template->id,
                        'nama_item' => $itemData['nama_item'],
                        'deskripsi' => $itemData['deskripsi'] ?? null,
                        'frekuensi' => $itemData['frekuensi'] ?? 'harian',
                        'hari_minggu' => isset($itemData['hari_minggu']) && $itemData['hari_minggu'] !== '' ? (int) $itemData['hari_minggu'] : null,
                        'tanggal_bulan' => isset($itemData['tanggal_bulan']) && $itemData['tanggal_bulan'] !== '' ? (int) $itemData['tanggal_bulan'] : null,
                    ]);
                }
            }

            $template->load('items');

            AuditLogService::log('CREATE_CHECKLIST_TEMPLATE', 'checklist_templates', $template->id, null, $template->toArray());

            return $this->success(new ChecklistTemplateResource($template), 'Template checklist berhasil dibuat.', 201);
        });
    }

    /**
     * GET /checklist-templates/{id} (admin, supervisor)
     */
    public function show($id)
    {
        $template = ChecklistTemplate::with('items')->findOrFail($id);
        return $this->success(new ChecklistTemplateResource($template), 'Detail template checklist berhasil diambil.');
    }

    /**
     * PUT/PATCH /checklist-templates/{id} (admin, supervisor)
     */
    public function update(Request $request, $id)
    {
        $template = ChecklistTemplate::findOrFail($id);
        $oldData = $template->load('items')->toArray();

        $request->validate([
            'nama_template' => ['required', 'string', 'max:255'],
            'deskripsi' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.id' => ['nullable', 'uuid'],
            'items.*.nama_item' => ['required_with:items', 'string', 'max:255'],
            'items.*.deskripsi' => ['nullable', 'string'],
            'items.*.frekuensi' => ['nullable', 'in:harian,mingguan,bulanan'],
            'items.*.hari_minggu' => ['nullable', 'integer', 'between:0,6'],
            'items.*.tanggal_bulan' => ['nullable', 'integer', 'between:1,31'],
        ]);

        return DB::transaction(function () use ($request, $template, $oldData) {
            $template->update([
                'nama_template' => $request->nama_template,
                'deskripsi' => $request->deskripsi,
            ]);

            if ($request->has('items') && is_array($request->items)) {
                $keptItemIds = [];
                foreach ($request->items as $itemData) {
                    $itemFreq = $itemData['frekuensi'] ?? 'harian';
                    $itemHari = isset($itemData['hari_minggu']) && $itemData['hari_minggu'] !== '' ? (int) $itemData['hari_minggu'] : null;
                    $itemTgl = isset($itemData['tanggal_bulan']) && $itemData['tanggal_bulan'] !== '' ? (int) $itemData['tanggal_bulan'] : null;

                    if (!empty($itemData['id'])) {
                        $item = ChecklistTemplateItem::where('id', $itemData['id'])
                            ->where('checklist_template_id', $template->id)
                            ->first();
                        if ($item) {
                            $item->update([
                                'nama_item' => $itemData['nama_item'],
                                'deskripsi' => $itemData['deskripsi'] ?? null,
                                'frekuensi' => $itemFreq,
                                'hari_minggu' => $itemHari,
                                'tanggal_bulan' => $itemTgl,
                            ]);
                            $keptItemIds[] = $item->id;
                            continue;
                        }
                    }

                    $newItem = ChecklistTemplateItem::create([
                        'id' => (string) Str::uuid(),
                        'checklist_template_id' => $template->id,
                        'nama_item' => $itemData['nama_item'],
                        'deskripsi' => $itemData['deskripsi'] ?? null,
                        'frekuensi' => $itemFreq,
                        'hari_minggu' => $itemHari,
                        'tanggal_bulan' => $itemTgl,
                    ]);
                    $keptItemIds[] = $newItem->id;
                }

                // Delete items removed from list
                ChecklistTemplateItem::where('checklist_template_id', $template->id)
                    ->whereNotIn('id', $keptItemIds)
                    ->delete();
            }

            $template->load('items');

            AuditLogService::log('UPDATE_CHECKLIST_TEMPLATE', 'checklist_templates', $template->id, $oldData, $template->toArray());

            return $this->success(new ChecklistTemplateResource($template), 'Template checklist berhasil diperbarui.');
        });
    }

    /**
     * DELETE /checklist-templates/{id} (admin)
     */
    public function destroy($id)
    {
        $template = ChecklistTemplate::findOrFail($id);
        $oldData = $template->toArray();

        // Soft delete
        $template->delete();

        AuditLogService::log('DELETE_CHECKLIST_TEMPLATE', 'checklist_templates', $template->id, $oldData, null);

        return $this->success(null, 'Template checklist berhasil dihapus.');
    }
}
