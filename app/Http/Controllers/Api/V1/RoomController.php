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
        $query = Room::query()
            ->whereHas('building')
            ->with(['building.shifts', 'pic', 'template', 'assets']);

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('nama_ruangan', 'like', "%{$search}%")
                  ->orWhere('kode_ruangan', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

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
                'kode_ruangan' => $data['kode_ruangan'],
                'nama_ruangan' => $data['nama_ruangan'],
                'lantai' => $data['lantai'] ?? null,
                'pic_user_id' => $data['pic_user_id'] ?? null,
                'checklist_template_id' => $data['checklist_template_id'] ?? null,
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

            $room->load(['building', 'pic', 'template']);

            AuditLogService::log('ROOM_CREATED', 'rooms', $room->id, null, $room->toArray());

            return $this->success(new RoomResource($room), 'Ruangan berhasil dibuat.', 201);
        });
    }

    /**
     * GET /rooms/{id} (admin, supervisor, pic)
     */
    public function show($id)
    {
        $room = Room::with(['building', 'pic', 'template', 'assets'])->findOrFail($id);
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
            $room->update([
                'building_id' => $data['building_id'],
                'kode_ruangan' => $data['kode_ruangan'],
                'nama_ruangan' => $data['nama_ruangan'],
                'lantai' => $data['lantai'] ?? null,
                'checklist_template_id' => $data['checklist_template_id'] ?? null,
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

            $room->load(['building', 'pic', 'template']);

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
     * DELETE /rooms/{id} (admin)
     */
    public function destroy($id)
    {
        $room = Room::findOrFail($id);
        $oldData = $room->toArray();

        return DB::transaction(function () use ($room, $oldData) {
            $room->update(['is_active' => false]);
            $room->delete();

            // Catat log audit
            AuditLogService::log('DELETE_ROOM', 'rooms', $room->id, $oldData, null);

            return $this->success(null, 'Ruangan berhasil dinonaktifkan dan di-soft delete. Data historis tetap terjaga.');
        });
    }
}

