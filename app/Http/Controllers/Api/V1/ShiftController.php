<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShiftController extends Controller
{
    use ApiResponse;

    /**
     * Tampilkan list shift kerja.
     */
    public function index(Request $request)
    {
        $shifts = Shift::orderBy('id', 'asc')->get();
        return $this->success(ShiftResource::collection($shifts), 'Daftar shift berhasil diambil.');
    }

    /**
     * Simpan shift baru (Admin Only).
     */
    public function store(Request $request)
    {
        $request->validate([
            'kode_shift' => ['required', 'string', 'max:5', 'unique:shifts,kode_shift'],
            'nama_shift' => ['required', 'string', 'max:50'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i'],
            'is_overnight' => ['required', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        if (!$user->hasRole(\App\Enums\RoleEnum::ADMIN)) {
            return $this->error('Akses ditolak. Hanya Admin yang dapat membuat shift.', [], 403);
        }

        $shift = Shift::create([
            'kode_shift' => $request->kode_shift,
            'nama_shift' => $request->nama_shift,
            'jam_mulai' => $request->jam_mulai,
            'jam_selesai' => $request->jam_selesai,
            'is_overnight' => $request->is_overnight,
            'is_active' => $request->is_active ?? true,
        ]);

        AuditLogService::log('CREATE_SHIFT', 'shifts', $shift->id, null, $shift->toArray());

        return $this->success(new ShiftResource($shift), 'Shift berhasil ditambahkan.', 201);
    }

    /**
     * Tampilkan detail shift.
     */
    public function show($id)
    {
        $shift = Shift::findOrFail($id);
        return $this->success(new ShiftResource($shift), 'Detail shift berhasil diambil.');
    }

    /**
     * Update shift (Admin Only).
     */
    public function update(Request $request, $id)
    {
        $shift = Shift::findOrFail($id);

        $request->validate([
            'kode_shift' => ['required', 'string', 'max:5', Rule::unique('shifts', 'kode_shift')->ignore($shift->id)],
            'nama_shift' => ['required', 'string', 'max:50'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i'],
            'is_overnight' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        if (!$user->hasRole(\App\Enums\RoleEnum::ADMIN)) {
            return $this->error('Akses ditolak. Hanya Admin yang dapat memperbarui shift.', [], 403);
        }

        $oldData = $shift->toArray();
        $shift->update([
            'kode_shift' => $request->kode_shift,
            'nama_shift' => $request->nama_shift,
            'jam_mulai' => $request->jam_mulai,
            'jam_selesai' => $request->jam_selesai,
            'is_overnight' => $request->is_overnight,
            'is_active' => $request->is_active,
        ]);

        AuditLogService::log('UPDATE_SHIFT', 'shifts', $shift->id, $oldData, $shift->toArray());

        return $this->success(new ShiftResource($shift), 'Shift berhasil diperbarui.');
    }

    /**
     * Hapus shift (Admin Only).
     */
    public function destroy(Request $request, $id)
    {
        $shift = Shift::findOrFail($id);

        $user = $request->user();
        if (!$user->hasRole(\App\Enums\RoleEnum::ADMIN)) {
            return $this->error('Akses ditolak. Hanya Admin yang dapat menghapus shift.', [], 403);
        }

        // Cek jika shift sudah terikat ke schedules atau tasks
        $hasSchedules = \App\Models\Schedule::where('shift_id', $shift->id)->exists();
        $hasTasks = \App\Models\Task::where('shift_id', $shift->id)->exists();

        if ($hasSchedules || $hasTasks) {
            // Jika sudah terikat, disable saja shift tersebut
            $oldData = $shift->toArray();
            $shift->update(['is_active' => false]);
            
            AuditLogService::log('DEACTIVATE_SHIFT', 'shifts', $shift->id, $oldData, $shift->toArray());
            
            return $this->success(
                new ShiftResource($shift),
                'Shift tidak dapat dihapus karena sudah memiliki data relasi. Status dinonaktifkan.'
            );
        }

        $oldData = $shift->toArray();
        $shift->delete();

        AuditLogService::log('DELETE_SHIFT', 'shifts', $id, $oldData, null);

        return $this->success(null, 'Shift berhasil dihapus.');
    }
}
