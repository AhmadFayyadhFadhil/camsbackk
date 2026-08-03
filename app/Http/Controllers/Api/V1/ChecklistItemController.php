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
            // 1. Dapatkan semua ID jadwal (schedules) yang menggunakan item checklist ini
            $scheduleIds = \Illuminate\Support\Facades\DB::table('schedules')
                ->where('checklist_item_id', $item->id)
                ->pluck('id');

            if ($scheduleIds->isNotEmpty()) {
                // Dapatkan semua ID tugas (tasks) yang terikat ke jadwal-jadwal tersebut
                $taskIds = \Illuminate\Support\Facades\DB::table('tasks')
                    ->whereIn('schedule_id', $scheduleIds)
                    ->pluck('id');

                if ($taskIds->isNotEmpty()) {
                    // Dapatkan semua ID laporan kebersihan (checklist_submissions)
                    $submissionIds = \Illuminate\Support\Facades\DB::table('checklist_submissions')
                        ->whereIn('task_id', $taskIds)
                        ->pluck('id');

                    if ($submissionIds->isNotEmpty()) {
                        // Hapus verifikasi terkait laporan ini
                        \Illuminate\Support\Facades\DB::table('verifications')
                            ->whereIn('submission_id', $submissionIds)
                            ->delete();

                        // Hapus detail hasil checklist terkait laporan ini
                        \Illuminate\Support\Facades\DB::table('checklist_results')
                            ->whereIn('submission_id', $submissionIds)
                            ->delete();

                        // Hapus laporan kebersihan
                        \Illuminate\Support\Facades\DB::table('checklist_submissions')
                            ->whereIn('id', $submissionIds)
                            ->delete();
                    }

                    // Hapus tugas
                    \Illuminate\Support\Facades\DB::table('tasks')
                        ->whereIn('id', $taskIds)
                        ->delete();
                }

                // Hapus jadwal
                \Illuminate\Support\Facades\DB::table('schedules')
                    ->whereIn('id', $scheduleIds)
                    ->delete();
            }

            // 2. Hapus sisa hasil checklist yang langsung merujuk ke item checklist ini
            \Illuminate\Support\Facades\DB::table('checklist_results')
                ->where('checklist_item_id', $item->id)
                ->delete();

            // 3. Hapus item checklist secara fisik
            $item->delete();

            // Catat log audit
            AuditLogService::log('DELETE_CHECKLIST_ITEM', 'checklist_items', $item->id, $oldData, null);

            return $this->success(null, 'Item checklist berhasil dihapus sepenuhnya beserta seluruh data terkait.');
        });
    }
}

