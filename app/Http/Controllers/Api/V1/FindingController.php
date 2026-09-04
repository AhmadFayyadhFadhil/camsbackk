<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FindingResource;
use App\Models\Finding;
use App\Enums\PriorityEnum;
use App\Enums\FindingStatusEnum;
use App\Enums\RoleEnum;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FindingController extends Controller
{
    use ApiResponse;

    /**
     * Tampilkan daftar temuan masalah (paginated).
     * OB hanya melihat findings miliknya sendiri.
     */
    public function index(Request $request)
    {
        $query = Finding::query()->with(['room.pic', 'reporter', 'asset']);

        // OB hanya boleh melihat laporan yang dia sendiri buat, ditugaskan kepadanya, ditugaskan ke eksternal, atau belum ditugaskan (untuk verifikasi)
        if ($request->user()->hasRole(RoleEnum::OB)) {
            $query->where(function ($q) use ($request) {
                $q->where('reported_by', $request->user()->id)
                  ->orWhere('assigned_to', $request->user()->id)
                  ->orWhereNotNull('assigned_to_external')
                  ->orWhere(function ($sub) {
                      $sub->whereNull('assigned_to')
                          ->whereNull('assigned_to_external');
                  });
            });
        }

        if ($request->has('status')) {
            $status = $request->get('status');
            if ($status === 'unresolved') {
                $query->whereIn('status', [FindingStatusEnum::OPEN->value, FindingStatusEnum::IN_PROGRESS->value]);
            } else {
                $query->where('status', $status);
            }
        }

        if ($request->has('prioritas')) {
            $query->where('prioritas', $request->get('prioritas'));
        }

        if ($request->has('room_id')) {
            $query->where('room_id', $request->get('room_id'));
        }

        if ($request->has('room_asset_id')) {
            $query->where('room_asset_id', $request->get('room_asset_id'));
        }

        $perPage = $request->get('per_page', 20);
        $findings = $query->paginate($perPage);

        return $this->paginated(
            FindingResource::collection($findings),
            'Daftar temuan masalah berhasil diambil.'
        );
    }

    /**
     * Laporkan temuan masalah.
     * - OB: wajib 4 foto (foto_ob_1 s/d foto_ob_4), tidak ada foto_temuan.
     * - Role lain (Admin, Supervisor, PIC, CS): wajib 1 foto (foto_temuan).
     */
    public function store(Request $request)
    {
        $request->validate([
            'room_id'             => ['required', 'uuid', 'exists:rooms,id'],
            'room_asset_id'       => ['nullable', 'uuid', 'exists:room_assets,id'],
            'finding_category_id' => ['nullable', 'uuid', 'exists:finding_categories,id'],
            'deskripsi'           => ['required', 'string'],
            'prioritas'           => ['required', 'string', 'in:low,medium,high'],
            'foto_temuan'         => ['required', 'image', 'max:1024'], // 1MB Limit
            'deadline_perbaikan'  => ['nullable', 'date'],
        ]);

        $findingData = [
            'id'                  => (string) Str::uuid(),
            'room_id'             => $request->room_id,
            'room_asset_id'       => $request->room_asset_id,
            'finding_category_id' => $request->finding_category_id,
            'reported_by'         => $request->user()->id,
            'deskripsi'           => $request->deskripsi,
            'prioritas'           => PriorityEnum::from($request->prioritas),
            'status'              => FindingStatusEnum::OPEN,
            'deadline_perbaikan'  => $request->deadline_perbaikan,
            'foto_finding'        => file_get_contents($request->file('foto_temuan')->getRealPath()),
            'foto_finding_mime'   => $request->file('foto_temuan')->getMimeType(),
        ];

        $finding = Finding::create($findingData);
        $finding->load(['room.pic', 'reporter', 'asset']);

        // Kirim notifikasi ke seluruh Supervisor aktif
        $supervisors = \App\Models\User::whereHas('roles', function ($q) {
            $q->where('name', RoleEnum::SUPERVISOR->value);
        })->where('is_active', true)->get();

        foreach ($supervisors as $supervisor) {
            \App\Services\NotificationService::send(
                $supervisor->id,
                'finding_reported',
                "Temuan Masalah: " . $finding->room->nama_ruangan,
                "Temuan baru dilaporkan oleh {$finding->reporter->full_name} di ruang {$finding->room->nama_ruangan}. Prioritas: {$finding->prioritas->value}.",
                [
                    'finding_id'    => $finding->id,
                    'room_name'     => $finding->room->nama_ruangan,
                    'reporter_name' => $finding->reporter->full_name,
                    'prioritas'     => $finding->prioritas->value,
                    'deskripsi'     => $finding->deskripsi,
                ],
                'both'
            );
        }

        AuditLogService::log(
            'CREATE_FINDING',
            'findings',
            $finding->id,
            null,
            $finding->toArray()
        );

        return $this->success(
            new FindingResource($finding),
            'Temuan masalah berhasil dilaporkan.',
            201
        );
    }

    /**
     * Tampilkan detail temuan.
     */
    public function show($id)
    {
        $finding = Finding::with(['room.pic', 'reporter', 'asset'])->findOrFail($id);
        return $this->success(new FindingResource($finding), 'Detail temuan masalah berhasil diambil.');
    }

    /**
     * Stream foto temuan utama (foto_finding) dari database.
     */
    public function streamFoto($id)
    {
        $finding = Finding::findOrFail($id);

        if (!$finding->foto_finding) {
            return $this->error('Foto temuan tidak ditemukan.', [], 404);
        }

        $mimeType = $finding->foto_finding_mime ?? 'image/jpeg';

        return response()->stream(function () use ($finding) {
            echo $finding->foto_finding;
        }, 200, [
            'Content-Type'        => $mimeType,
            'Cache-Control'       => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="finding_' . $finding->id . '.jpg"',
        ]);
    }

    /**
     * Stream foto selesai perbaikan (foto_selesai) dari database.
     */
    public function streamFotoResolved($id)
    {
        $finding = Finding::findOrFail($id);

        if (!$finding->foto_selesai) {
            return $this->error('Foto bukti selesai perbaikan tidak ditemukan.', [], 404);
        }

        $mimeType = $finding->foto_selesai_mime ?? 'image/jpeg';

        return response()->stream(function () use ($finding) {
            echo $finding->foto_selesai;
        }, 200, [
            'Content-Type'        => $mimeType,
            'Cache-Control'       => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="resolved_' . $finding->id . '.jpg"',
        ]);
    }

    /**
     * Stream foto OB 1 dari database.
     */
    public function streamFotoOb1($id)
    {
        $finding = Finding::findOrFail($id);

        if (!$finding->foto_ob_1) {
            return $this->error('Foto OB 1 tidak ditemukan.', [], 404);
        }

        $mimeType = $finding->foto_ob_1_mime ?? 'image/jpeg';

        return response()->stream(function () use ($finding) {
            echo $finding->foto_ob_1;
        }, 200, [
            'Content-Type'        => $mimeType,
            'Cache-Control'       => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="ob_1_' . $finding->id . '.jpg"',
        ]);
    }

    /**
     * Stream foto OB 2 dari database.
     */
    public function streamFotoOb2($id)
    {
        $finding = Finding::findOrFail($id);

        if (!$finding->foto_ob_2) {
            return $this->error('Foto OB 2 tidak ditemukan.', [], 404);
        }

        $mimeType = $finding->foto_ob_2_mime ?? 'image/jpeg';

        return response()->stream(function () use ($finding) {
            echo $finding->foto_ob_2;
        }, 200, [
            'Content-Type'        => $mimeType,
            'Cache-Control'       => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="ob_2_' . $finding->id . '.jpg"',
        ]);
    }

    /**
     * Stream foto OB 3 dari database.
     */
    public function streamFotoOb3($id)
    {
        $finding = Finding::findOrFail($id);

        if (!$finding->foto_ob_3) {
            return $this->error('Foto OB 3 tidak ditemukan.', [], 404);
        }

        $mimeType = $finding->foto_ob_3_mime ?? 'image/jpeg';

        return response()->stream(function () use ($finding) {
            echo $finding->foto_ob_3;
        }, 200, [
            'Content-Type'        => $mimeType,
            'Cache-Control'       => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="ob_3_' . $finding->id . '.jpg"',
        ]);
    }

    /**
     * Stream foto OB 4 dari database.
     */
    public function streamFotoOb4($id)
    {
        $finding = Finding::findOrFail($id);

        if (!$finding->foto_ob_4) {
            return $this->error('Foto OB 4 tidak ditemukan.', [], 404);
        }

        $mimeType = $finding->foto_ob_4_mime ?? 'image/jpeg';

        return response()->stream(function () use ($finding) {
            echo $finding->foto_ob_4;
        }, 200, [
            'Content-Type'        => $mimeType,
            'Cache-Control'       => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="ob_4_' . $finding->id . '.jpg"',
        ]);
    }

    /**
     * Ubah status temuan masalah (CS, Supervisor, Admin).
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status'               => ['required', 'string', 'in:open,in_progress,resolved'],
            'deadline_perbaikan'   => ['nullable', 'date'],
            'foto_selesai'         => ['nullable', 'image', 'max:1024'], // 1MB Limit
            'assigned_to'          => ['nullable', 'uuid', 'exists:users,id'],
            'assigned_to_external' => ['nullable', 'string', 'max:255'],
        ]);

        $user = $request->user();
        $finding = Finding::findOrFail($id);

        $isAssigned = ($finding->assigned_to === $user->id);
        $isExternalOrUnassigned = (empty($finding->assigned_to) && empty($finding->assigned_to_external)) || !empty($finding->assigned_to_external);

        // Otorisasi: Admin, Supervisor, CS, petugas yang ditunjuk (isAssigned), atau pelapor
        $canUpdateStatus = $user->hasRole(RoleEnum::ADMIN) || 
                           $user->hasRole(RoleEnum::SUPERVISOR) || 
                           $user->hasRole(RoleEnum::CS) ||
                           $isAssigned ||
                           ($finding->reported_by === $user->id);

        if (!$canUpdateStatus) {
            return $this->error('Akses ditolak. Anda tidak memiliki otorisasi untuk mengubah status temuan ini.', [], 403);
        }

        $oldData = $finding->toArray();
        $statusVal = FindingStatusEnum::from($request->status);

        $updateData = [
            'status' => $statusVal,
        ];

        if ($request->has('deadline_perbaikan')) {
            $updateData['deadline_perbaikan'] = $request->deadline_perbaikan;
        }

        $isAdminOrSupervisor = $user->hasRole(RoleEnum::ADMIN) || $user->hasRole(RoleEnum::SUPERVISOR);
        if ($isAdminOrSupervisor) {
            $isAssigning = false;
            if ($request->exists('assigned_to')) {
                if ($request->assigned_to !== $finding->assigned_to) {
                    $updateData['assigned_to'] = $request->assigned_to;
                    if (!empty($request->assigned_to)) {
                        $updateData['assigned_to_external'] = null; // Bersihkan eksternal jika internal diisi
                        $isAssigning = true;
                    }
                }
            }
            if ($request->exists('assigned_to_external')) {
                if ($request->assigned_to_external !== $finding->assigned_to_external) {
                    $updateData['assigned_to_external'] = $request->assigned_to_external;
                    if (!empty($request->assigned_to_external)) {
                        $updateData['assigned_to'] = null; // Bersihkan internal jika eksternal diisi
                        $isAssigning = true;
                    }
                }
            }
            if ($isAssigning) {
                $updateData['assigned_at'] = now();
            }
        }

        if ($statusVal === FindingStatusEnum::RESOLVED) {
            $updateData['resolved_at'] = now();
            $assignedTime = $finding->assigned_at ?? $finding->created_at;
            if ($assignedTime) {
                $updateData['response_time_minutes'] = (int) now()->diffInMinutes($assignedTime);
            }
            if ($request->hasFile('foto_selesai')) {
                $updateData['foto_selesai']      = file_get_contents($request->file('foto_selesai')->getRealPath());
                $updateData['foto_selesai_mime'] = $request->file('foto_selesai')->getMimeType();
            }
        } else {
            $updateData['resolved_at']       = null;
            $updateData['foto_selesai']      = null;
            $updateData['foto_selesai_mime'] = null;
        }

        $oldAssignedTo = $finding->assigned_to;
        $finding->update($updateData);
        $finding->refresh();
        $finding->load(['room.pic', 'reporter']);

        // Kirim notifikasi jika petugas internal baru di-assign
        if ($finding->assigned_to && $finding->assigned_to !== $oldAssignedTo) {
            \App\Services\NotificationService::send(
                $finding->assigned_to,
                'finding_assigned',
                "Penugasan Perbaikan: " . $finding->room?->nama_ruangan,
                "Anda telah ditugaskan oleh Supervisor untuk memperbaiki kerusakan: {$finding->deskripsi} di ruang {$finding->room?->nama_ruangan}.",
                [
                    'finding_id' => $finding->id,
                    'room_name'  => $finding->room?->nama_ruangan,
                    'deskripsi'  => $finding->deskripsi,
                    'prioritas'  => $finding->prioritas?->value,
                ],
                'both'
            );
        }

        AuditLogService::log(
            'UPDATE_FINDING_STATUS',
            'findings',
            $finding->id,
            $oldData,
            $finding->toArray()
        );

        return $this->success(
            new FindingResource($finding),
            'Status temuan masalah berhasil diperbarui.'
        );
    }

    /**
     * Hapus laporan temuan masalah (Admin, PIC dari ruangan terkait).
     */
    public function destroy(Request $request, $id)
    {
        $user    = $request->user();
        $finding = Finding::findOrFail($id);

        $isAdmin = $user->hasRole(RoleEnum::ADMIN);
        $isPic   = $user->hasRole(RoleEnum::PIC);

        if (!$isAdmin && !$isPic) {
            return $this->error('Akses ditolak. Hanya Admin atau PIC yang dapat menghapus laporan temuan ini.', [], 403);
        }

        $oldData = $finding->toArray();
        $finding->delete();

        AuditLogService::log(
            'DELETE_FINDING',
            'findings',
            $id,
            $oldData,
            null
        );

        return $this->success(null, 'Laporan temuan kerusakan berhasil dihapus.');
    }
}
