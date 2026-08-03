<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ScheduleRequest;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use App\Models\Room;
use App\Enums\FrequencyEnum;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    use ApiResponse;

    /**
     * GET /schedules (admin, supervisor)
     */
    public function index(Request $request)
    {
        $query = Schedule::query()->with(['room', 'shift', 'checklistItem']);

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
}

