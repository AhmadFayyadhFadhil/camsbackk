<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\BuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Http\Resources\ShiftResource;
use App\Models\Building;
use App\Models\Task;
use App\Models\CsAssignment;
use App\Models\Room;
use App\Models\Schedule;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BuildingController extends Controller
{
    use ApiResponse;

    /**
     * GET /buildings (admin, supervisor)
     */
    public function index(Request $request)
    {
        $query = Building::query()->with('shifts')->withCount(['rooms' => function ($q) {
            $q->where('is_active', true);
        }]);

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('nama_gedung', 'like', "%{$search}%")
                  ->orWhere('kode_gedung', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = $request->get('per_page', 20);
        $buildings = $query->paginate($perPage);

        return $this->paginated(BuildingResource::collection($buildings), 'Daftar gedung berhasil diambil.');
    }

    /**
     * POST /buildings (admin)
     */
    public function store(BuildingRequest $request)
    {
        $data = $request->validated();
        
        // Terjemahkan parameter frontend ke backend
        if (isset($data['name'])) {
            $data['nama_gedung'] = $data['name'];
        }
        if (isset($data['code'])) {
            $data['kode_gedung'] = $data['code'];
        }
        if (isset($data['description'])) {
            $data['alamat'] = $data['description'];
        }
        
        // Bersihkan parameter alias
        unset($data['name'], $data['code'], $data['description']);
        
        $data['created_by'] = Auth::id();
        
        $building = Building::create($data);
        $building->load('shifts');

        AuditLogService::log('CREATE_BUILDING', 'buildings', $building->id, null, $building->toArray());

        return $this->success(new BuildingResource($building), 'Gedung berhasil dibuat.', 201);
    }

    /**
     * GET /buildings/{id} (admin, supervisor)
     */
    public function show($id)
    {
        $today = today()->toDateString();
        $building = Building::with('shifts')->findOrFail($id);

        // Cari CS yang bertugas di gedung ini hari ini
        $assignments = CsAssignment::where('building_id', $building->id)
            ->where('tanggal_mulai', '<=', $today)
            ->where(function($q) use ($today) {
                $q->whereNull('tanggal_selesai')
                  ->orWhere('tanggal_selesai', '>=', $today);
            })
            ->with(['cs', 'shift'])
            ->get();

        $csToday = $assignments->map(fn($assign) => [
            'cs_user_id' => $assign->cs_user_id,
            'full_name' => $assign->cs?->full_name,
            'username' => $assign->cs?->username,
            'shift' => [
                'id' => $assign->shift?->id,
                'kode_shift' => $assign->shift?->kode_shift,
                'nama_shift' => $assign->shift?->nama_shift,
            ]
        ]);

        return $this->success([
            'building' => new BuildingResource($building),
            'cs_today' => $csToday
        ], 'Detail gedung berhasil diambil.');
    }

    /**
     * PATCH /buildings/{id} (admin)
     */
    public function update(BuildingRequest $request, $id)
    {
        $building = Building::findOrFail($id);
        $oldData = $building->toArray();
        
        $data = $request->validated();
        
        // Terjemahkan parameter frontend ke backend
        if (isset($data['name'])) {
            $data['nama_gedung'] = $data['name'];
        }
        if (isset($data['code'])) {
            $data['kode_gedung'] = $data['code'];
        }
        if (isset($data['description'])) {
            $data['alamat'] = $data['description'];
        }
        
        // Bersihkan parameter alias
        unset($data['name'], $data['code'], $data['description']);
        
        $building->update($data);
        $building->load('shifts');
        
        AuditLogService::log('UPDATE_BUILDING', 'buildings', $building->id, $oldData, $building->toArray());

        return $this->success(new BuildingResource($building), 'Gedung berhasil diperbarui.');
    }

    /**
     * PATCH /buildings/{id}/deactivate (admin)
     */
    public function deactivate($id)
    {
        $building = Building::findOrFail($id);
        $oldData = $building->toArray();

        return DB::transaction(function () use ($building, $oldData) {
            $building->update(['is_active' => false]);
            $building->delete();

            AuditLogService::log('DELETE_BUILDING', 'buildings', $building->id, $oldData, null);

            return $this->success(null, 'Gedung berhasil dinonaktifkan dan di-soft delete. Data historis tetap terjaga.');
        });
    }

    /**
     * GET /buildings/{id}/shifts (admin, supervisor)
     */
    public function getShifts($id)
    {
        $building = Building::findOrFail($id);
        return $this->success(
            ShiftResource::collection($building->shifts()->where('is_active', true)->get()),
            'Daftar shift aktif gedung berhasil diambil.'
        );
    }

    /**
     * POST /buildings/{id}/shifts (admin, supervisor)
     */
    public function assignShifts(Request $request, $id)
    {
        $request->validate([
            'shift_ids' => ['required', 'array'],
            'shift_ids.*' => ['required', 'integer', 'exists:shifts,id'],
        ]);

        $building = Building::findOrFail($id);
        $oldShifts = $building->shifts()->pluck('shifts.id')->toArray();
        
        $building->shifts()->sync($request->shift_ids);
        
        $newShifts = $building->shifts()->pluck('shifts.id')->toArray();
        $removedShiftIds = array_values(array_diff($oldShifts, $newShifts));
        $addedShiftIds = array_values(array_diff($newShifts, $oldShifts));

        $roomIds = $building->rooms()->pluck('id');

        // 1. Jika ada shift yang dilepas/dihapus dari gedung:
        if (!empty($removedShiftIds)) {
            // Nonaktifkan jadwal master untuk shift yang dilepas
            Schedule::whereIn('room_id', $roomIds)
                ->whereIn('shift_id', $removedShiftIds)
                ->update(['is_active' => false]);

            // Bersihkan / hapus tugas harian pending hari ini yang belum dikerjakan untuk shift tersebut
            Task::whereIn('room_id', $roomIds)
                ->whereIn('shift_id', $removedShiftIds)
                ->where('status', \App\Enums\TaskStatusEnum::PENDING)
                ->whereDate('tanggal_task', today()->toDateString())
                ->delete();
        }

        // 2. Jika ada shift baru yang ditambahkan ke gedung:
        if (!empty($addedShiftIds)) {
            // Aktifkan kembali jadwal master ruangan yang sebelumnya pernah dibuat untuk shift ini
            Schedule::whereIn('room_id', $roomIds)
                ->whereIn('shift_id', $addedShiftIds)
                ->update(['is_active' => true]);

            // Generate tugas harian untuk shift aktif hari ini
            $generator = new \App\Services\TaskGeneratorService();
            $generator->generateForDate(today());
        }

        AuditLogService::log(
            'ASSIGN_SHIFTS_TO_BUILDING',
            'building_shifts',
            $building->id,
            ['shift_ids' => $oldShifts],
            ['shift_ids' => $newShifts]
        );

        return $this->success(new BuildingResource($building->load('shifts')), 'Shift berhasil dikaitkan dengan gedung dan sinkronisasi tugas telah diperbarui.');
    }

    /**
     * DELETE /buildings/{id}/shifts/{sid} (admin, supervisor)
     */
    public function removeShift($id, $sid)
    {
        $building = Building::findOrFail($id);
        $building->shifts()->detach($sid);

        $roomIds = $building->rooms()->pluck('id');

        // Nonaktifkan jadwal master dan hapus tugas pending
        Schedule::whereIn('room_id', $roomIds)
            ->where('shift_id', $sid)
            ->update(['is_active' => false]);

        Task::whereIn('room_id', $roomIds)
            ->where('shift_id', $sid)
            ->where('status', \App\Enums\TaskStatusEnum::PENDING)
            ->whereDate('tanggal_task', today()->toDateString())
            ->delete();

        AuditLogService::log(
            'REMOVE_SHIFT_FROM_BUILDING',
            'building_shifts',
            $building->id,
            ['shift_id' => $sid],
            null
        );

        return $this->success(new BuildingResource($building->load('shifts')), 'Shift berhasil dilepas dari gedung dan tugas terkait telah dibersihkan.');
    }

    /**
     * DELETE /buildings/{id} (admin)
     */
    public function destroy($id)
    {
        return $this->deactivate($id);
    }
}

