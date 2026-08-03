<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\UserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use App\Models\CsAssignment;
use App\Models\RoomPicHistory;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    use ApiResponse;

    /**
     * GET /users (admin)
     */
    public function index(Request $request)
    {
        $query = User::query()->with('roles');

        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->get('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = $request->get('per_page', 20);
        $users = $query->paginate($perPage);

        return $this->paginated(UserResource::collection($users), 'Daftar user berhasil diambil.');
    }

    /**
     * POST /users (admin)
     */
    public function store(UserRequest $request)
    {
        $data = $request->validated();

        return DB::transaction(function () use ($data) {
            // 1. Buat User
            $user = User::create([
                'username' => $data['username'],
                'email' => $data['email'],
                'password' => Hash::make($data['password'], ['rounds' => 12]),
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? null,
                'is_active' => isset($data['is_active']) ? (bool)$data['is_active'] : true,
            ]);

            // 2. Sync Roles (Manually insert to UserRole)
            $roles = Role::whereIn('name', $data['roles'])->get();
            foreach ($roles as $role) {
                UserRole::create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'assigned_by' => Auth::id(),
                ]);
            }

            $user->load('roles');

            AuditLogService::log('CREATE_USER', 'users', $user->id, null, $user->toArray());

            // 3. Kirim welcome notification (In-app + email)
            NotificationService::send(
                $user->id,
                'WELCOME',
                'Selamat Datang di CAMS',
                "Halo {$user->full_name}, akun Anda di Cleaning Activity Monitoring System (CAMS) PT Widatra Bhakti telah berhasil dibuat.",
                [
                    'username' => $user->username,
                    'email' => $user->email,
                ],
                'both'
            );

            return $this->success(new UserResource($user), 'User berhasil dibuat.', 201);
        });
    }

    /**
     * GET /users/{id} (admin)
     */
    public function show($id)
    {
        $user = User::with(['roles', 'activeAssignment.shift', 'activeAssignment.building'])->findOrFail($id);
        return $this->success(new UserResource($user), 'Detail user berhasil diambil.');
    }

    /**
     * PATCH /users/{id} (admin)
     */
    public function update(UserRequest $request, $id)
    {
        $user = User::findOrFail($id);
        $oldData = $user->load('roles')->toArray();
        $data = $request->validated();

        return DB::transaction(function () use ($user, $data, $oldData) {
            $updateData = [
                'username' => $data['username'],
                'email' => $data['email'],
                'full_name' => $data['full_name'],
                'phone' => $data['phone'] ?? null,
            ];

            if (isset($data['is_active'])) {
                $updateData['is_active'] = (bool)$data['is_active'];
            }

            if (!empty($data['password'])) {
                $updateData['password'] = Hash::make($data['password'], ['rounds' => 12]);
            }

            $user->update($updateData);

            // Sync Roles
            UserRole::where('user_id', $user->id)->delete();
            $roles = Role::whereIn('name', $data['roles'])->get();
            foreach ($roles as $role) {
                UserRole::create([
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'assigned_by' => Auth::id(),
                ]);
            }

            $user->load('roles');

            AuditLogService::log('UPDATE_USER', 'users', $user->id, $oldData, $user->toArray());

            return $this->success(new UserResource($user), 'User berhasil diperbarui.');
        });
    }

    /**
     * PATCH /users/{id}/deactivate (admin)
     */
    public function deactivate($id)
    {
        $user = User::findOrFail($id);

        // Proteksi Admin agar tidak bisa dihapus
        if ($user->hasRole('admin')) {
            return $this->error('Pengguna dengan peran Admin tidak dapat dihapus.', null, 400);
        }

        $oldData = $user->toArray();

        return DB::transaction(function () use ($user, $oldData) {
            // 1. Hapus semua Token Akses Sanctum
            $user->tokens()->delete();

            // 2. Hapus histori PIC ruangan
            RoomPicHistory::where('user_id', $user->id)->delete();

            // 3. Hapus penugasan CS
            CsAssignment::where('cs_user_id', $user->id)->delete();

            // 4. Hapus temuan masalah yang dilaporkan oleh user
            DB::table('findings')->where('reported_by', $user->id)->delete();

            // 5. Hapus verifikasi yang dilakukan oleh user
            DB::table('verifications')->where('verified_by', $user->id)->delete();

            // 6. Ambil semua ID submissions oleh user (sebagai CS)
            $submissionIds = DB::table('checklist_submissions')->where('cs_user_id', $user->id)->pluck('id');

            if ($submissionIds->isNotEmpty()) {
                // Hapus verifikasi terkait submissions ini
                DB::table('verifications')->whereIn('submission_id', $submissionIds)->delete();

                // Hapus hasil checklist terkait submissions ini
                DB::table('checklist_results')->whereIn('submission_id', $submissionIds)->delete();

                // Hapus submissions
                DB::table('checklist_submissions')->whereIn('id', $submissionIds)->delete();
            }

            // 7. Hapus hak akses (user_roles)
            UserRole::where('user_id', $user->id)->delete();

            // 8. Hapus user secara fisik dari database
            $user->delete();

            // Catat log audit
            AuditLogService::log('DELETE_USER', 'users', $user->id, $oldData, null);

            return $this->success(null, 'Pengguna berhasil dihapus sepenuhnya.');
        });
    }

    /**
     * DELETE /users/{id} (admin)
     */
    public function destroy($id)
    {
        return $this->deactivate($id);
    }

    /**
     * Ambil daftar staf aktif yang bisa di-assign tugas perbaikan (Admin/Supervisor).
     */
    public function assignableUsers(Request $request)
    {
        $users = User::where('is_active', true)
            ->whereHas('roles', function ($q) {
                $q->whereIn('name', ['cs']);
            })
            ->whereDoesntHave('roles', function ($q) {
                $q->whereIn('name', ['admin', 'supervisor']);
            })
            ->with(['roles', 'activeAssignment.shift', 'activeAssignment.building'])
            ->get();

        $sortedUsers = $users->sortByDesc(function ($user) {
            $assignment = $user->activeAssignment;
            if ($assignment && $assignment->shift) {
                return \App\Helpers\ShiftValidatorHelper::isWithinShift($assignment->shift) ? 2 : 1;
            }
            return 0;
        })->values();

        return $this->success(UserResource::collection($sortedUsers), 'Daftar staf aktif berhasil diambil.');
    }
}

