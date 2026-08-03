<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\CsAssignmentRequest;
use App\Http\Resources\CsAssignmentResource;
use App\Models\CsAssignment;
use App\Models\User;
use App\Enums\RoleEnum;
use App\Services\AuditLogService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CsAssignmentController extends Controller
{
    use ApiResponse;

    /**
     * GET /cs-assignments (admin, supervisor)
     */
    public function index(Request $request)
    {
        $query = CsAssignment::query()->with(['cs', 'building', 'shift']);

        if ($request->has('cs_user_id')) {
            $query->where('cs_user_id', $request->get('cs_user_id'));
        }

        if ($request->has('building_id')) {
            $query->where('building_id', $request->get('building_id'));
        }

        $perPage = $request->get('per_page', 20);
        $assignments = $query->paginate($perPage);

        return $this->paginated(CsAssignmentResource::collection($assignments), 'Daftar penugasan CS berhasil diambil.');
    }

    /**
     * POST /cs-assignments (admin)
     */
    public function store(CsAssignmentRequest $request)
    {
        $data = $request->validated();

        // 1. Validasi role CS
        $user = User::findOrFail($data['cs_user_id']);
        if (!$user->hasRole(RoleEnum::CS)) {
            return $this->error('Validasi gagal.', [
                'cs_user_id' => ['User yang ditugaskan harus memiliki peran CS.']
            ], 422);
        }

        // 2. Cek Overlap
        $overlapExists = $this->hasOverlap($data['cs_user_id'], $data['shift_id'] ?? null, $data['tanggal_mulai'], $data['tanggal_selesai'] ?? null);
        if ($overlapExists) {
            return $this->error('Validasi gagal.', [
                'overlap' => ['CS sudah ditugaskan pada gedung/shift dengan tanggal kerja yang tumpang tindih.']
            ], 422);
        }

        $data['created_by'] = Auth::id();

        $assignment = CsAssignment::create($data);
        $assignment->load(['cs', 'building', 'shift']);

        AuditLogService::log('CREATE_CS_ASSIGNMENT', 'cs_assignments', $assignment->id, null, $assignment->toArray());

        return $this->success(new CsAssignmentResource($assignment), 'Penugasan CS berhasil dibuat.', 201);
    }

    /**
     * GET /cs-assignments/{id} (admin, supervisor)
     */
    public function show($id)
    {
        $assignment = CsAssignment::with(['cs', 'building', 'shift'])->findOrFail($id);
        return $this->success(new CsAssignmentResource($assignment), 'Detail penugasan CS berhasil diambil.');
    }

    /**
     * PATCH /cs-assignments/{id} (admin)
     */
    public function update(CsAssignmentRequest $request, $id)
    {
        $assignment = CsAssignment::findOrFail($id);
        $oldData = $assignment->toArray();
        $data = $request->validated();

        // 1. Validasi role CS
        $user = User::findOrFail($data['cs_user_id']);
        if (!$user->hasRole(RoleEnum::CS)) {
            return $this->error('Validasi gagal.', [
                'cs_user_id' => ['User yang ditugaskan harus memiliki peran CS.']
            ], 422);
        }

        // 2. Cek Overlap
        $overlapExists = $this->hasOverlap($data['cs_user_id'], $data['shift_id'] ?? null, $data['tanggal_mulai'], $data['tanggal_selesai'] ?? null, $assignment->id);
        if ($overlapExists) {
            return $this->error('Validasi gagal.', [
                'overlap' => ['CS sudah ditugaskan pada gedung/shift dengan tanggal kerja yang tumpang tindih.']
            ], 422);
        }

        $assignment->update($data);
        $assignment->load(['cs', 'building', 'shift']);

        AuditLogService::log('UPDATE_CS_ASSIGNMENT', 'cs_assignments', $assignment->id, $oldData, $assignment->toArray());

        return $this->success(new CsAssignmentResource($assignment), 'Penugasan CS berhasil diperbarui.');
    }

    /**
     * PATCH /cs-assignments/{id}/end (admin)
     */
    public function endAssignment($id)
    {
        $assignment = CsAssignment::findOrFail($id);
        $oldData = $assignment->toArray();

        $today = today()->toDateString();
        
        // Atur tanggal selesai menjadi hari ini
        $assignment->update([
            'tanggal_selesai' => $today
        ]);

        AuditLogService::log('END_CS_ASSIGNMENT', 'cs_assignments', $assignment->id, $oldData, $assignment->toArray());

        return $this->success(new CsAssignmentResource($assignment->load(['cs', 'building', 'shift'])), 'Penugasan CS berhasil diakhiri hari ini.');
    }

    /**
     * Memeriksa overlap date range CS assignments
     */
    private function hasOverlap(string $csUserId, ?int $shiftId, string $tanggalMulai, ?string $tanggalSelesai, ?string $excludeId = null): bool
    {
        $query = CsAssignment::where('cs_user_id', $csUserId)
            ->when($excludeId, function($q) use ($excludeId) {
                $q->where('id', '!=', $excludeId);
            });

        if (is_null($shiftId)) {
            // New assignment is general (no shift), so it overlaps with ANY assignment
        } else {
            // New assignment is specific, so it overlaps if existing is general (null) OR matches the same shift
            $query->where(function($q) use ($shiftId) {
                $q->whereNull('shift_id')
                  ->orWhere('shift_id', $shiftId);
            });
        }

        // SQL overlap formula: StartA <= EndB AND StartB <= EndA
        $query->where(function($q) use ($tanggalMulai, $tanggalSelesai) {
            if ($tanggalSelesai) {
                $q->where('tanggal_mulai', '<=', $tanggalSelesai)
                  ->where(function($sub) use ($tanggalMulai) {
                      $sub->whereNull('tanggal_selesai')
                          ->orWhere('tanggal_selesai', '>=', $tanggalMulai);
                  });
            } else {
                $q->whereNull('tanggal_selesai')
                  ->orWhere('tanggal_selesai', '>=', $tanggalMulai);
            }
        });

        return $query->exists();
    }

    /**
     * DELETE /cs-assignments/{id} (admin)
     */
    public function destroy($id)
    {
        $assignment = CsAssignment::findOrFail($id);
        $oldData = $assignment->toArray();
        
        $assignment->delete();

        AuditLogService::log('DELETE_CS_ASSIGNMENT', 'cs_assignments', $id, $oldData, null);

        return $this->success(null, 'Penugasan CS berhasil dihapus.');
    }
}

