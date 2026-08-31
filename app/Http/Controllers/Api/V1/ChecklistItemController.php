<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ChecklistItemRequest;
use App\Http\Resources\ChecklistItemResource;
use App\Models\ChecklistItem;
use App\Models\Schedule;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChecklistItemController extends Controller
{
    use ApiResponse;

    /**
     * GET /checklist-items (admin, supervisor)
     */
    public function index(Request $request)
    {
        $query = ChecklistItem::query();

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where('nama_item', 'like', "%{$search}%")
                  ->orWhere('kategori', 'like', "%{$search}%");
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = $request->get('per_page', 20);
        $items = $query->paginate($perPage);

        return $this->paginated(ChecklistItemResource::collection($items), 'Daftar item kebersihan berhasil diambil.');
    }

    /**
     * POST /checklist-items (admin)
     */
    public function store(ChecklistItemRequest $request)
    {
        $data = $request->validated();
        $data['created_by'] = Auth::id();
        
        $item = ChecklistItem::create($data);

        AuditLogService::log('CREATE_CHECKLIST_ITEM', 'checklist_items', $item->id, null, $item->toArray());

        return $this->success(new ChecklistItemResource($item), 'Item kebersihan berhasil dibuat.', 201);
    }

    /**
     * GET /checklist-items/{id} (admin, supervisor)
     */
    public function show($id)
    {
        $item = ChecklistItem::findOrFail($id);
        return $this->success(new ChecklistItemResource($item), 'Detail item kebersihan berhasil diambil.');
    }

    /**
     * PATCH /checklist-items/{id} (admin)
     */
    public function update(ChecklistItemRequest $request, $id)
    {
        $item = ChecklistItem::findOrFail($id);
        $oldData = $item->toArray();
        $data = $request->validated();

        $item->update($data);

        AuditLogService::log('UPDATE_CHECKLIST_ITEM', 'checklist_items', $item->id, $oldData, $item->toArray());

        return $this->success(new ChecklistItemResource($item), 'Item kebersihan berhasil diperbarui.');
    }

    /**
     * PATCH /checklist-items/{id}/deactivate (admin)
     */
    public function deactivate(Request $request, $id)
    {
        $item = ChecklistItem::findOrFail($id);
        $oldData = $item->toArray();

        // Check if item is used in any active schedules
        $hasActiveSchedule = Schedule::where('checklist_item_id', $item->id)
            ->where('is_active', true)
            ->exists();

        $force = filter_var($request->get('force'), FILTER_VALIDATE_BOOLEAN);

        if ($hasActiveSchedule && !$force) {
            return $this->error('Warning: Item ini masih digunakan di schedules aktif. Gunakan parameter force=true untuk memaksa deaktifasi.', null, 400);
        }

        $item->update(['is_active' => false]);

        AuditLogService::log('DEACTIVATE_CHECKLIST_ITEM', 'checklist_items', $item->id, $oldData, $item->toArray());

        return $this->success(new ChecklistItemResource($item), 'Item kebersihan berhasil dinonaktifkan.');
    }

    /**
     * DELETE /checklist-items/{id} (admin)
     */
    public function destroy(Request $request, $id)
    {
        $item = ChecklistItem::findOrFail($id);
        $oldData = $item->toArray();

        return \Illuminate\Support\Facades\DB::transaction(function () use ($item, $oldData) {
            $item->update(['is_active' => false]);
            $item->delete();

            // Catat log audit
            AuditLogService::log('DELETE_CHECKLIST_ITEM', 'checklist_items', $item->id, $oldData, null);

            return $this->success(null, 'Item checklist berhasil dinonaktifkan dan di-soft delete. Data historis tetap terjaga.');
        });
    }
}

