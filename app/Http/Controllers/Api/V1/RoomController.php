<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\RoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Models\User;
use App\Models\RoomPicHistory;
use App\Services\QrCodeService;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use App\Enums\RoleEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    use ApiResponse;

    protected QrCodeService $qrCodeService;

    public function __construct(QrCodeService $qrCodeService)
    {
        $this->qrCodeService = $qrCodeService;
    }

    /**
     * GET /rooms (admin, supervisor)
     */
    public function index(Request $request)
    {
        // Join ke room_areas hanya untuk pengurutan/pencarian per area (urutan sesuai sumber data Excel).
        $query = Room::query()
            ->select('rooms.*')
            ->leftJoin('room_areas', 'room_areas.id', '=', 'rooms.room_area_id')
            ->whereHas('building')
            ->with(['building.shifts', 'pic', 'template', 'assets', 'area']);

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('rooms.nama_ruangan', 'like', "%{$search}%")
                  ->orWhere('rooms.kode_ruangan', 'like', "%{$search}%")
                  ->orWhere('room_areas.nama_area', 'like', "%{$search}%");
            });
        }

        if ($request->filled('room_area_id')) {
            $areaFilter = $request->get('room_area_id');
            if ($areaFilter === 'none') {
                $query->whereNull('rooms.room_area_id');
            } else {
                $query->where('rooms.room_area_id', $areaFilter);
            }
        }

        if ($request->has('is_active')) {
            $query->where('rooms.is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // Urutan: area (sesuai Excel) -> urutan ruangan dalam area -> nama. Ruangan tanpa area di paling bawah.
        $query->orderByRaw('room_areas.urutan IS NULL')
              ->orderBy('room_areas.urutan')
              ->orderByRaw('rooms.urutan_area IS NULL')
              ->orderBy('rooms.urutan_area')
              ->orderBy('rooms.nama_ruangan');

        $perPage = $request->get('per_page', 20);
        $rooms = $query->paginate($perPage);

        return $this->paginated(RoomResource::collection($rooms), 'Daftar ruangan berhasil diambil.');
    }

    /**
     * POST /rooms (admin)
     */
    public function store(RoomRequest $request)
    {
        $data = $request->validated();

        // Validasi PIC jika diisi
        if (!empty($data['pic_user_id'])) {
            $picUser = User::findOrFail($data['pic_user_id']);
            if (!$picUser->hasRole(RoleEnum::PIC)) {
                return $this->error('Validasi gagal.', ['pic_user_id' => ['User yang dipilih harus memiliki peran PIC.']], 422);
            }
        }

        return DB::transaction(function () use ($data) {
            $roomId = (string) Str::uuid();
            $token = (string) Str::uuid();

            // 1. Generate QR Code
            $qrBinary = $this->qrCodeService->generate($roomId, $token, $data['building_id']);

            // 2. Create Room
            $room = Room::create([
                'id' => $roomId,
                'building_id' => $data['building_id'],
                'room_area_id' => $data['room_area_id'] ?? null,
                'kode_ruangan' => $data['kode_ruangan'],
                'nama_ruangan' => $data['nama_ruangan'],
                'lantai' => $data['lantai'] ?? null,
                'pic_user_id' => $data['pic_user_id'] ?? null,
                'checklist_template_id' => $data['checklist_template_id'] ?? null,
                'latitude' => isset($data['latitude']) && $data['latitude'] !== '' ? $data['latitude'] : null,
                'longitude' => isset($data['longitude']) && $data['longitude'] !== '' ? $data['longitude'] : null,
                'radius_meter' => isset($data['radius_meter']) && $data['radius_meter'] !== '' ? (int)$data['radius_meter'] : null,
                'qr_code_token' => $token,
                'qr_code_image' => $qrBinary,
                'is_active' => true,
            ]);

            // 3. Create PIC History jika PIC diisi
            if (!empty($data['pic_user_id'])) {
                RoomPicHistory::create([
                    'room_id' => $room->id,
                    'user_id' => $data['pic_user_id'],
                    'tanggal_mulai' => today(),
                ]);
            }

            $room->load(['building', 'pic', 'template', 'area']);

            AuditLogService::log('ROOM_CREATED', 'rooms', $room->id, null, $room->toArray());

            return $this->success(new RoomResource($room), 'Ruangan berhasil dibuat.', 201);
        });
    }

    /**
     * GET /rooms/{id} (admin, supervisor, pic)
     */
    public function show($id)
    {
        $room = Room::with(['building', 'pic', 'template', 'assets', 'area'])->findOrFail($id);
        return $this->success(new RoomResource($room), 'Detail ruangan berhasil diambil.');
    }

    /**
     * PATCH /rooms/{id} (admin)
     */
    public function update(RoomRequest $request, $id)
    {
        $room = Room::findOrFail($id);
        $oldData = $room->toArray();
        $data = $request->validated();

        // Validasi PIC jika diisi
        if (!empty($data['pic_user_id'])) {
            $picUser = User::findOrFail($data['pic_user_id']);
            if (!$picUser->hasRole(RoleEnum::PIC)) {
                return $this->error('Validasi gagal.', ['pic_user_id' => ['User yang dipilih harus memiliki peran PIC.']], 422);
            }
        }

        return DB::transaction(function () use ($room, $data, $oldData) {
            // Jika area dipindah, urutan lama tidak berlaku lagi (ruangan diurutkan menurut nama di area barunya).
            $newAreaId = array_key_exists('room_area_id', $data) ? ($data['room_area_id'] ?: null) : $room->room_area_id;
            $areaChanged = $newAreaId !== $room->room_area_id;

            $room->update([
                'building_id' => $data['building_id'],
                'room_area_id' => $newAreaId,
                'urutan_area' => $areaChanged ? null : $room->urutan_area,
                'kode_ruangan' => $data['kode_ruangan'],
                'nama_ruangan' => $data['nama_ruangan'],
                'lantai' => $data['lantai'] ?? null,
                'checklist_template_id' => $data['checklist_template_id'] ?? null,
                'latitude' => array_key_exists('latitude', $data) ? ($data['latitude'] !== '' && $data['latitude'] !== null ? $data['latitude'] : null) : $room->latitude,
                'longitude' => array_key_exists('longitude', $data) ? ($data['longitude'] !== '' && $data['longitude'] !== null ? $data['longitude'] : null) : $room->longitude,
                'radius_meter' => array_key_exists('radius_meter', $data) ? ($data['radius_meter'] !== '' && $data['radius_meter'] !== null ? (int)$data['radius_meter'] : null) : $room->radius_meter,
            ]);

            // Tangani pergantian PIC
            $newPicId = $data['pic_user_id'] ?? null;
            if ($room->pic_user_id !== $newPicId) {
                // Akhiri PIC sebelumnya
                if ($room->pic_user_id) {
                    RoomPicHistory::where('room_id', $room->id)
                        ->where('user_id', $room->pic_user_id)
                        ->whereNull('tanggal_selesai')
                        ->update(['tanggal_selesai' => today()->subDay()]);
                }

                // Tambah PIC baru
                if ($newPicId) {
                    RoomPicHistory::create([
                        'room_id' => $room->id,
                        'user_id' => $newPicId,
                        'tanggal_mulai' => today(),
                    ]);
                }

                $room->update(['pic_user_id' => $newPicId]);
            }

            $room->load(['building', 'pic', 'template', 'area']);

            AuditLogService::log('UPDATE_ROOM', 'rooms', $room->id, $oldData, $room->toArray());

            return $this->success(new RoomResource($room), 'Ruangan berhasil diperbarui.');
        });
    }

    /**
     * PATCH /rooms/{id}/pic (admin)
     */
    public function updatePic(Request $request, $id)
    {
        $request->validate([
            'pic_user_id' => ['required', 'uuid', 'exists:users,id']
        ]);

        $room = Room::findOrFail($id);
        $newPicId = $request->pic_user_id;

        // Validasi PIC
        $picUser = User::findOrFail($newPicId);
        if (!$picUser->hasRole(RoleEnum::PIC)) {
            return $this->error('Validasi gagal.', ['pic_user_id' => ['User yang dipilih harus memiliki peran PIC.']], 422);
        }

        if ($room->pic_user_id === $newPicId) {
            return $this->success(new RoomResource($room->load(['building', 'pic'])), 'PIC ruangan tidak berubah.');
        }

        $oldData = $room->toArray();

        return DB::transaction(function () use ($room, $newPicId, $oldData) {
            // Akhiri PIC sebelumnya
            if ($room->pic_user_id) {
                RoomPicHistory::where('room_id', $room->id)
                    ->where('user_id', $room->pic_user_id)
                    ->whereNull('tanggal_selesai')
                    ->update(['tanggal_selesai' => today()->subDay()]);
            }

            // Tambah PIC baru
            RoomPicHistory::create([
                'room_id' => $room->id,
                'user_id' => $newPicId,
                'tanggal_mulai' => today(),
            ]);

            $room->update(['pic_user_id' => $newPicId]);
            $room->load(['building', 'pic']);

            AuditLogService::log('ROOM_PIC_UPDATED', 'rooms', $room->id, $oldData, $room->toArray());

            return $this->success(new RoomResource($room), 'PIC ruangan berhasil diperbarui.');
        });
    }

    /**
     * PATCH /rooms/{id}/deactivate (admin)
     */
    public function deactivate($id)
    {
        $room = Room::findOrFail($id);
        $oldData = $room->toArray();

        $room->update(['is_active' => false]);

        AuditLogService::log('ROOM_DEACTIVATED', 'rooms', $room->id, $oldData, $room->toArray());

        return $this->success(new RoomResource($room->load(['building', 'pic'])), 'Ruangan berhasil dinonaktifkan.');
    }

    /**
     * GET /rooms/{id}/pic-history (admin, supervisor)
     */
    public function picHistory($id)
    {
        $room = Room::findOrFail($id);
        $history = RoomPicHistory::where('room_id', $room->id)
            ->with('user')
            ->orderBy('tanggal_mulai', 'desc')
            ->get()
            ->map(fn($item) => [
                'id' => $item->id,
                'user_id' => $item->user_id,
                'full_name' => $item->user?->full_name,
                'username' => $item->user?->username,
                'tanggal_mulai' => $item->tanggal_mulai?->toDateString(),
                'tanggal_selesai' => $item->tanggal_selesai?->toDateString(),
            ]);

        return $this->success($history, 'History pergantian PIC berhasil diambil.');
    }

    /**
     * POST /rooms/{id}/qr-code/regenerate (admin)
     */
    public function regenerateQrCode($id)
    {
        $room = Room::findOrFail($id);
        $oldData = $room->toArray();

        $token = (string) Str::uuid();
        $qrBinary = $this->qrCodeService->generate($room->id, $token, $room->building_id);

        $room->update([
            'qr_code_token' => $token,
            'qr_code_image' => $qrBinary,
        ]);

        AuditLogService::log('ROOM_QR_REGENERATED', 'rooms', $room->id, $oldData, $room->toArray());

        return $this->success(new RoomResource($room->load(['building', 'pic'])), 'QR Code ruangan berhasil di-regenerate.');
    }

    /**
     * GET /rooms/{id}/qr-code/download (admin)
     */
    public function downloadQrCode($id)
    {
        $room = Room::findOrFail($id);

        if (!$room->qr_code_image) {
            return $this->error('QR Code tidak ditemukan untuk ruangan ini.', [], 404);
        }

        // Streaming binary PNG langsung dari DB ke browser
        return response($room->qr_code_image, 200)
            ->header('Content-Type', 'image/png')
            ->header('Content-Disposition', 'attachment; filename="QR_' . $room->kode_ruangan . '.png"');
    }

    /**
     * DELETE /rooms/{id} (admin, supervisor)
     */
    public function destroy($id)
    {
        $room = Room::findOrFail($id);
        $oldData = $room->toArray();

        return DB::transaction(function () use ($room, $oldData) {
            // 1. Bersihkan tugas, submission, hasil checklist terkait ruangan ini
            $taskIds = \App\Models\Task::where('room_id', $room->id)->pluck('id');
            if ($taskIds->isNotEmpty()) {
                $subIds = \App\Models\ChecklistSubmission::whereIn('task_id', $taskIds)->pluck('id');
                if ($subIds->isNotEmpty()) {
                    DB::table('verifications')->whereIn('submission_id', $subIds)->delete();
                    DB::table('submission_materials')->whereIn('submission_id', $subIds)->delete();
                    \App\Models\ChecklistResult::whereIn('submission_id', $subIds)->delete();
                    \App\Models\ChecklistSubmission::whereIn('id', $subIds)->delete();
                }
                \App\Models\Task::whereIn('id', $taskIds)->delete();
            }

            // 2. Bersihkan seluruh jadwal pada ruangan ini
            \App\Models\Schedule::where('room_id', $room->id)->delete();

            // 3. Bersihkan riwayat audit aset ruangan ini
            $auditIds = \App\Models\RoomAssetAudit::where('room_id', $room->id)->pluck('id');
            if ($auditIds->isNotEmpty()) {
                DB::table('room_asset_audit_items')->whereIn('room_asset_audit_id', $auditIds)->delete();
                \App\Models\RoomAssetAudit::whereIn('id', $auditIds)->delete();
            }

            // 4. Bersihkan aset ruangan
            \App\Models\RoomAsset::where('room_id', $room->id)->delete();

            // 5. Deaktivasi dan soft delete ruangan
            $room->update(['is_active' => false]);
            $room->delete();

            // Catat log audit
            AuditLogService::log('DELETE_ROOM', 'rooms', $room->id, $oldData, null);

            return $this->success(null, 'Ruangan dan seluruh data terkait berhasil dihapus sepenuhnya.');
        });
    }
}

