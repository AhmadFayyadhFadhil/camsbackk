<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Http\Resources\CsAssignmentResource;
use App\Http\Resources\RoomResource;
use App\Http\Resources\BuildingResource;
use App\Http\Resources\ShiftResource;
use App\Http\Resources\ChecklistItemResource;
use App\Http\Resources\UserResource;
use App\Models\Schedule;
use App\Models\Room;
use App\Models\CsAssignment;
use App\Models\Building;
use App\Models\Shift;
use App\Models\ChecklistItem;
use App\Models\User;
use App\Models\Task;
use App\Enums\FrequencyEnum;
use App\Enums\RoleEnum;
use App\Enums\TaskStatusEnum;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    use ApiResponse;

    /**
     * GET /schedules/init-data (admin, supervisor)
     * Menggabungkan pengambilan master jadwal dan seluruh dependensi lookup dalam 1 request tunggal yang super cepat.
     */
    public function initData(Request $request)
    {
        $schedules = Schedule::query()
            ->with(['room.building', 'shift', 'checklistItem'])
            ->get();

        $assignments = CsAssignment::query()
            ->with(['cs', 'building', 'shift'])
            ->get();

        $rooms = Room::query()
            ->where('is_active', true)
            ->with(['building.shifts', 'pic'])
            ->get();

        $buildings = Building::query()
            ->where('is_active', true)
            ->with('shifts')
            ->get();

        $checklistItems = ChecklistItem::query()
            ->where('is_active', true)
            ->get();

        $checklistTemplates = \App\Models\ChecklistTemplate::query()
            ->with('items')
            ->get();

        $shifts = Shift::query()
            ->where('is_active', true)
            ->get();

        $csUsers = User::query()
            ->whereHas('roles', function ($q) {
                $q->where('name', RoleEnum::CS->value);
            })
            ->where('is_active', true)
            ->get();

        return $this->success([
            'schedules' => ScheduleResource::collection($schedules),
            'assignments' => CsAssignmentResource::collection($assignments),
            'rooms' => RoomResource::collection($rooms),
            'buildings' => BuildingResource::collection($buildings),
            'checklist_items' => ChecklistItemResource::collection($checklistItems),
            'checklist_templates' => $checklistTemplates->map(fn($t) => [
                'id' => $t->id,
                'nama_template' => $t->nama_template,
                'deskripsi' => $t->deskripsi,
                'items' => $t->items->map(fn($i) => [
                    'id' => $i->id,
                    'nama_item' => $i->nama_item,
                    'deskripsi' => $i->deskripsi,
                    'frekuensi' => $i->frekuensi ?? 'harian',
                    'hari_minggu' => $i->hari_minggu,
                    'tanggal_bulan' => $i->tanggal_bulan,
                ])
            ]),
            'shifts' => ShiftResource::collection($shifts),
            'cs_users' => UserResource::collection($csUsers),
        ], 'Data inisialisasi jadwal berhasil diambil.');
    }

    /**
     * GET /schedules (admin, supervisor)
     */
    public function index(Request $request)
    {
        $query = Schedule::query()->with(['room.building', 'shift', 'checklistItem']);

        if ($request->has('room_id')) {
            $query->where('room_id', $request->get('room_id'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = $request->get('per_page', 20);
        $schedules = $query->paginate($perPage);

        return $this->paginated(ScheduleResource::collection($schedules), 'Daftar jadwal berhasil diambil.');
    }

    /**
     * POST /schedules (admin)
     */
    public function store(ScheduleRequest $request)
    {
        $data = $request->validated();
        
        $room = Room::findOrFail($data['room_id']);
        
        // 1. Validasi: shift harus ada di building_shifts gedung ruangan tersebut
        $shiftIsValid = DB::table('building_shifts')
            ->where('building_id', $room->building_id)
            ->where('shift_id', $data['shift_id'])
            ->where('is_active', true)
            ->exists();

        if (!$shiftIsValid) {
            return $this->error('Validasi gagal.', [
                'shift_id' => ['Shift yang dipilih tidak dialokasikan untuk gedung tempat ruangan ini berada.']
            ], 422);
        }

        // 2. Validasi Frekuensi & Pembersihan Kolom
        $frekuensi = $data['frekuensi'];
        if ($frekuensi === FrequencyEnum::HARIAN->value) {
            $data['hari_minggu'] = null;
            $data['tanggal_bulan'] = null;
        } elseif ($frekuensi === FrequencyEnum::MINGGUAN->value) {
            if (!isset($data['hari_minggu'])) {
                return $this->error('Validasi gagal.', [
                    'hari_minggu' => ['Hari dalam seminggu (hari_minggu) wajib diisi untuk frekuensi mingguan.']
                ], 422);
            }
            $data['tanggal_bulan'] = null;
        } elseif ($frekuensi === FrequencyEnum::BULANAN->value) {
            if (!isset($data['tanggal_bulan'])) {
                return $this->error('Validasi gagal.', [
                    'tanggal_bulan' => ['Tanggal dalam sebulan (tanggal_bulan) wajib diisi untuk frekuensi bulanan.']
                ], 422);
            }
            $data['hari_minggu'] = null;
        }

        // Check Unique constraint manually to return a nice message
        $exists = Schedule::where('room_id', $data['room_id'])
            ->where('checklist_item_id', $data['checklist_item_id'])
            ->where('shift_id', $data['shift_id'])
            ->where('frekuensi', $data['frekuensi'])
            ->exists();

        if ($exists) {
            return $this->error('Validasi gagal.', [
                'unique_schedule' => ['Jadwal untuk ruangan, item checklist, shift, dan frekuensi ini sudah ada.']
            ], 422);
        }

        $schedule = Schedule::create($data);
        $schedule->load(['room', 'shift', 'checklistItem']);

        try {
            $generator = resolve(\App\Services\TaskGeneratorService::class);
            $generator->generateForDate(\Carbon\Carbon::today('Asia/Jakarta'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal generate task otomatis saat membuat jadwal: ' . $e->getMessage());
        }

        AuditLogService::log('CREATE_SCHEDULE', 'schedules', $schedule->id, null, $schedule->toArray());

        return $this->success(new ScheduleResource($schedule), 'Jadwal berhasil dibuat.', 201);
    }

    /**
     * GET /schedules/{id} (admin, supervisor)
     */
    public function show($id)
    {
        $schedule = Schedule::with(['room', 'shift', 'checklistItem'])->findOrFail($id);
        return $this->success(new ScheduleResource($schedule), 'Detail jadwal berhasil diambil.');
    }

    /**
     * PATCH /schedules/{id} (admin)
     */
    public function update(ScheduleRequest $request, $id)
    {
        $schedule = Schedule::findOrFail($id);
        $oldData = $schedule->toArray();
        $data = $request->validated();

        $room = Room::findOrFail($data['room_id']);

        // 1. Validasi: shift harus sesuai gedung
        $shiftIsValid = DB::table('building_shifts')
            ->where('building_id', $room->building_id)
            ->where('shift_id', $data['shift_id'])
            ->where('is_active', true)
            ->exists();

        if (!$shiftIsValid) {
            return $this->error('Validasi gagal.', [
                'shift_id' => ['Shift yang dipilih tidak dialokasikan untuk gedung tempat ruangan ini berada.']
            ], 422);
        }

        // 2. Validasi Frekuensi & Pembersihan Kolom
        $frekuensi = $data['frekuensi'];
        if ($frekuensi === FrequencyEnum::HARIAN->value) {
            $data['hari_minggu'] = null;
            $data['tanggal_bulan'] = null;
        } elseif ($frekuensi === FrequencyEnum::MINGGUAN->value) {
            if (!isset($data['hari_minggu'])) {
                return $this->error('Validasi gagal.', [
                    'hari_minggu' => ['Hari dalam seminggu (hari_minggu) wajib diisi untuk frekuensi mingguan.']
                ], 422);
            }
            $data['tanggal_bulan'] = null;
        } elseif ($frekuensi === FrequencyEnum::BULANAN->value) {
            if (!isset($data['tanggal_bulan'])) {
                return $this->error('Validasi gagal.', [
                    'tanggal_bulan' => ['Tanggal dalam sebulan (tanggal_bulan) wajib diisi untuk frekuensi bulanan.']
                ], 422);
            }
            $data['hari_minggu'] = null;
        }

        // Check unique constraint excluding self
        $exists = Schedule::where('room_id', $data['room_id'])
            ->where('checklist_item_id', $data['checklist_item_id'])
            ->where('shift_id', $data['shift_id'])
            ->where('frekuensi', $data['frekuensi'])
            ->where('id', '!=', $id)
            ->exists();

        if ($exists) {
            return $this->error('Validasi gagal.', [
                'unique_schedule' => ['Jadwal untuk ruangan, item checklist, shift, dan frekuensi ini sudah ada.']
            ], 422);
        }

        $schedule->update($data);
        $schedule->load(['room', 'shift', 'checklistItem']);

        try {
            $generator = resolve(\App\Services\TaskGeneratorService::class);
            $generator->generateForDate(\Carbon\Carbon::today('Asia/Jakarta'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal generate task otomatis saat update jadwal: ' . $e->getMessage());
        }

        AuditLogService::log('UPDATE_SCHEDULE', 'schedules', $schedule->id, $oldData, $schedule->toArray());

        return $this->success(new ScheduleResource($schedule), 'Jadwal berhasil diperbarui.');
    }

    /**
     * POST /schedules/apply-template (admin, supervisor)
     * Menerapkan seluruh atau sebagian item dalam checklist template ke ruangan dan shift tertentu sekaligus.
     */
    public function applyTemplate(Request $request)
    {
        $request->validate([
            'room_id' => ['required', 'string', 'exists:rooms,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'template_id' => ['required', 'string', 'exists:checklist_templates,id'],
            'frekuensi' => ['required', 'string', 'in:harian,mingguan,bulanan,daily,weekly,monthly'],
            'hari_minggu' => ['nullable', 'integer', 'between:0,6'],
            'tanggal_bulan' => ['nullable', 'integer', 'between:1,31'],
            'target_jam_mulai' => ['nullable'],
            'target_jam_selesai' => ['nullable'],
            'estimasi_durasi_menit' => ['nullable', 'integer', 'min:1', 'max:480'],
            'urutan' => ['nullable', 'integer', 'min:1'],
            'item_ids' => ['nullable', 'array'],
        ]);

        $room = Room::findOrFail($request->room_id);

        // Validasi: Shift harus dialokasikan pada gedung ruangan
        $shiftIsValid = DB::table('building_shifts')
            ->where('building_id', $room->building_id)
            ->where('shift_id', $request->shift_id)
            ->where('is_active', true)
            ->exists();

        if (!$shiftIsValid) {
            return $this->error('Validasi gagal.', [
                'shift_id' => ['Shift yang dipilih tidak dialokasikan untuk gedung tempat ruangan ini berada.']
            ], 422);
        }

        $template = \App\Models\ChecklistTemplate::with('items')->findOrFail($request->template_id);
        if ($template->items->isEmpty()) {
            return $this->error('Template checklist ini tidak memiliki item.');
        }

        $frekuensi = strtolower($request->frekuensi);
        if ($frekuensi === 'daily') $frekuensi = FrequencyEnum::HARIAN->value;
        if ($frekuensi === 'weekly') $frekuensi = FrequencyEnum::MINGGUAN->value;
        if ($frekuensi === 'monthly') $frekuensi = FrequencyEnum::BULANAN->value;

        $itemsToApply = $template->items;
        if (!empty($request->item_ids)) {
            $itemsToApply = $itemsToApply->whereIn('id', $request->item_ids);
        }

        $createdCount = 0;
        $reactivatedCount = 0;
        $skippedCount = 0;

        $appliedChecklistItemIds = [];

        foreach ($itemsToApply as $tItem) {
            $checklistItem = ChecklistItem::firstOrCreate(
                ['nama_item' => $tItem->nama_item],
                ['kategori' => 'General', 'is_active' => true]
            );

            $appliedChecklistItemIds[] = $checklistItem->id;

            // Prioritaskan frekuensi khusus dari item template jika diset, jika tidak gunakan frekuensi global
            $itemFreq = !empty($tItem->frekuensi) ? $tItem->frekuensi : $frekuensi;
            $itemHari = $itemFreq === FrequencyEnum::MINGGUAN->value ? ($tItem->hari_minggu ?? $request->hari_minggu) : null;
            $itemTgl = $itemFreq === FrequencyEnum::BULANAN->value ? ($tItem->tanggal_bulan ?? $request->tanggal_bulan) : null;

            $existingSchedule = Schedule::where('room_id', $room->id)
                ->where('checklist_item_id', $checklistItem->id)
                ->where('shift_id', $request->shift_id)
                ->first();

            if ($existingSchedule) {
                $existingSchedule->update([
                    'frekuensi' => $itemFreq,
                    'hari_minggu' => $itemHari,
                    'tanggal_bulan' => $itemTgl,
                    'target_jam_mulai' => $request->target_jam_mulai ?: $existingSchedule->target_jam_mulai,
                    'target_jam_selesai' => $request->target_jam_selesai ?: $existingSchedule->target_jam_selesai,
                    'is_active' => true,
                ]);
                $reactivatedCount++;
            } else {
                Schedule::create([
                    'room_id' => $room->id,
                    'checklist_item_id' => $checklistItem->id,
                    'shift_id' => $request->shift_id,
                    'frekuensi' => $itemFreq,
                    'hari_minggu' => $itemHari,
                    'tanggal_bulan' => $itemTgl,
                    'target_jam_mulai' => $request->target_jam_mulai,
                    'target_jam_selesai' => $request->target_jam_selesai,
                    'is_active' => true,
                ]);
                $createdCount++;
            }
        }

        // Clean up any outdated schedules for this room & shift that are NOT in the applied template items
        $outdatedSchedules = Schedule::where('room_id', $room->id)
            ->where('shift_id', $request->shift_id)
            ->whereNotIn('checklist_item_id', $appliedChecklistItemIds)
            ->get();

        foreach ($outdatedSchedules as $oldSched) {
            Task::where('schedule_id', $oldSched->id)
                ->where('status', TaskStatusEnum::PENDING->value)
                ->delete();

            $oldSched->delete();
        }

        // Clean up any orphaned schedules for this room with null/missing checklist items
        Schedule::where('room_id', $room->id)
            ->whereDoesntHave('checklistItem')
            ->delete();

        // Update room's checklist_template_id to match the newly applied template
        $room->update(['checklist_template_id' => $template->id]);

        // Generate task untuk hari ini jika jadwal harian dibuat
        try {
            $generator = resolve(\App\Services\TaskGeneratorService::class);
            $generator->generateForDate(\Carbon\Carbon::today('Asia/Jakarta'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Gagal generate task otomatis saat apply template: ' . $e->getMessage());
        }

        AuditLogService::log(
            'APPLY_TEMPLATE_TO_SCHEDULES',
            'schedules',
            $room->id,
            null,
            [
                'template_id' => $template->id,
                'template_name' => $template->nama_template,
                'created_count' => $createdCount,
                'reactivated_count' => $reactivatedCount,
                'skipped_count' => $skippedCount,
            ]
        );

        $totalAffected = $createdCount + $reactivatedCount;
        return $this->success([
            'created_count' => $createdCount,
            'reactivated_count' => $reactivatedCount,
            'skipped_count' => $skippedCount,
            'total_affected' => $totalAffected
        ], "Berhasil menerapkan template '{$template->nama_template}'. {$totalAffected} jadwal berhasil disiapkan.");
    }

    /**
     * PATCH /schedules/{id}/deactivate (admin)
     */
    public function deactivate($id)
    {
        $schedule = Schedule::findOrFail($id);
        $oldData = $schedule->toArray();

        $schedule->update(['is_active' => false]);

        AuditLogService::log('DEACTIVATE_SCHEDULE', 'schedules', $schedule->id, $oldData, $schedule->toArray());

        return $this->success(new ScheduleResource($schedule), 'Jadwal berhasil dinonaktifkan.');
    }

    /**
     * DELETE /schedules/{id} (admin)
     */
    public function destroy($id)
    {
        return $this->deactivate($id);
    }

    /**
     * DELETE /schedules/clear-all (admin, supervisor)
     * Menghapus / me-reset seluruh master jadwal kebersihan dan tugas pending terkait.
     */
    public function clearAll(Request $request)
    {
        return DB::transaction(function() {
            // Hapus verifikasi dan pivot bahan kimia
            DB::table('verifications')->delete();
            DB::table('submission_materials')->delete();

            // Hapus hasil checklist & submission tugas uji coba
            \App\Models\ChecklistResult::query()->delete();
            \App\Models\ChecklistSubmission::query()->delete();

            // Hapus seluruh data tugas
            Task::query()->delete();

            // Hapus seluruh master jadwal
            $deletedSchedules = Schedule::query()->delete();

            AuditLogService::log('CLEAR_ALL_SCHEDULES', 'schedules', null, [], ['count' => $deletedSchedules]);

            return $this->success([
                'deleted_schedules_count' => $deletedSchedules
            ], "Seluruh master data jadwal ({$deletedSchedules} jadwal) dan tugas berhasil dibersihkan.");
        });
    }
}

