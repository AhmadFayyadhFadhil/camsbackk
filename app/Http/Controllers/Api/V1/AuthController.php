<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Traits\ApiResponse;
use App\Enums\RoleEnum;
use App\Helpers\ShiftValidatorHelper;
use App\Services\NotificationService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/v1/auth/login
     */
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();
        
        $loginIdentifier = $credentials['username'] ?? $credentials['email'] ?? null;
        
        // 1. Cari user by username atau email
        $user = User::where('username', $loginIdentifier)
            ->orWhere('email', $loginIdentifier)
            ->first();

        // 2. Verifikasi password & status aktif
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return $this->error('Username atau email atau password salah.', null, 401);
        }

        if (!$user->is_active) {
            return $this->error('Akun Anda dinonaktifkan. Silakan hubungi Administrator.', null, 403);
        }

        // 3. Hitung expiry: CS = 480 menit (8 jam), lainnya = 1440 menit (24 jam)
        $expiryMinutes = $user->hasRole(RoleEnum::CS)
            ? (int) config('sanctum.token_expiry_cs', 480)
            : (int) config('sanctum.token_expiry_default', 1440);

        $expiresAt = Carbon::now()->addMinutes($expiryMinutes);
        
        // 5. Buat Sanctum token
        $token = $user->createToken('cams-session', ['*'], $expiresAt)->plainTextToken;

        // 6. Update last_login_at
        $user->update([
            'last_login_at' => Carbon::now(),
        ]);

        // 7. Catat AuditLog USER_LOGIN
        AuditLogService::log('USER_LOGIN', 'users', $user->id, null, [
            'username' => $user->username,
            'ip_address' => $request->ip()
        ]);

        return $this->success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => new UserResource($user->load(['roles', 'activeAssignment.shift', 'activeAssignment.building']))
        ], 'Login berhasil.');
    }

    /**
     * POST /api/v1/auth/logout
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        
        if ($user) {
            // Hapus token aktif
            $user->currentAccessToken()->delete();
            
            // Catat AuditLog USER_LOGOUT
            AuditLogService::log('USER_LOGOUT', 'users', $user->id, null, [
                'username' => $user->username
            ]);
        }

        return $this->success(null, 'Logout berhasil.');
    }

    /**
     * POST /api/v1/auth/change-password
     */
    public function changePassword(ChangePasswordRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();

        // 1. Verifikasi current_password
        if (!Hash::check($data['current_password'], $user->password)) {
            return $this->error('Password lama salah.', [
                'current_password' => ['Password lama tidak cocok dengan database.']
            ], 422);
        }

        $oldData = ['password' => 'PROTECTED'];
        
        // 2. Update password (rounds = 12)
        $user->update([
            'password' => Hash::make($data['new_password'], ['rounds' => 12])
        ]);

        // 3. Revoke token lain
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        // 4. Catat AuditLog PASSWORD_CHANGED
        AuditLogService::log('PASSWORD_CHANGED', 'users', $user->id, $oldData, [
            'password' => 'PROTECTED'
        ]);

        return $this->success(null, 'Password berhasil diubah.');
    }

    /**
     * POST /api/v1/auth/reset-password/{userId} (Role: admin)
     */
    public function resetPassword(Request $request, string $userId)
    {
        $user = User::findOrFail($userId);

        // 1. Generate password sementara
        $temporaryPassword = Str::password(10);

        $oldData = ['password' => 'PROTECTED'];

        // 2. Hash dan simpan (rounds = 12)
        $user->update([
            'password' => Hash::make($temporaryPassword, ['rounds' => 12])
        ]);

        // 3. Revoke semua token user tersebut
        $user->tokens()->delete();

        // 4. Kirim email & notifikasi via NotificationService
        NotificationService::send(
            $user->id,
            'PASSWORD_RESET',
            'Password Reset Sistem',
            "Password Anda telah di-reset oleh Admin. Password baru sementara Anda adalah: {$temporaryPassword}",
            ['temporary_password' => $temporaryPassword],
            'both'
        );

        // 5. Catat AuditLog PASSWORD_RESET_BY_ADMIN
        AuditLogService::log('PASSWORD_RESET_BY_ADMIN', 'users', $user->id, $oldData, [
            'password' => 'PROTECTED'
        ]);

        return $this->success([
            'temporary_password' => $temporaryPassword
        ], 'Password user berhasil di-reset. Email berisi password sementara telah dikirim.');
    }

    /**
     * GET /api/v1/auth/me
     */
    public function me(Request $request)
    {
        $user = $request->user();
        
        $relations = ['roles'];
        if ($user->hasRole(RoleEnum::CS)) {
            $relations[] = 'activeAssignment.shift';
            $relations[] = 'activeAssignment.building';
        }
        if ($user->hasRole(RoleEnum::PIC)) {
            $relations[] = 'picHistories.room';
        }

        return $this->success(
            new UserResource($user->load($relations)),
            'Data profil berhasil diambil.'
        );
    }

    /**
     * POST /api/v1/auth/profile
     * Update data profil user (Nama Lengkap, Nomor Telepon, dan Foto Profil)
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'full_name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'foto_profile' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:3072'],
            'remove_photo' => ['nullable'],
        ], [
            'full_name.required' => 'Nama lengkap wajib diisi.',
            'foto_profile.image' => 'File foto profil harus berupa gambar yang valid (JPEG, PNG, WEBP).',
            'foto_profile.max' => 'Ukuran foto profil maksimal 3 MB.',
        ]);

        $oldData = [
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'foto_profile' => $user->foto_profile,
        ];

        $updateData = [
            'full_name' => $request->full_name,
            'phone' => $request->phone,
        ];

        // Handle penghapusan foto profil
        $removePhoto = filter_var($request->input('remove_photo'), FILTER_VALIDATE_BOOLEAN);
        if ($removePhoto) {
            if ($user->foto_profile && Storage::disk('public')->exists($user->foto_profile)) {
                Storage::disk('public')->delete($user->foto_profile);
            }
            $updateData['foto_profile'] = null;
        }

        // Handle upload foto profil baru
        if ($request->hasFile('foto_profile')) {
            // Hapus foto lama jika ada
            if ($user->foto_profile && Storage::disk('public')->exists($user->foto_profile)) {
                Storage::disk('public')->delete($user->foto_profile);
            }

            $path = $request->file('foto_profile')->store('avatars', 'public');
            $updateData['foto_profile'] = $path;
        }

        $user->update($updateData);

        AuditLogService::log('PROFILE_UPDATED', 'users', $user->id, $oldData, $updateData);

        $relations = ['roles'];
        if ($user->hasRole(RoleEnum::CS)) {
            $relations[] = 'activeAssignment.shift';
            $relations[] = 'activeAssignment.building';
        }
        if ($user->hasRole(RoleEnum::PIC)) {
            $relations[] = 'picHistories.room';
        }

        return $this->success(
            new UserResource($user->load($relations)),
            'Profil berhasil diperbarui.'
        );
    }

    /**
     * GET /api/v1/auth/avatar/{userId}
     * Stream foto profil user secara aman
     */
    public function streamAvatar(string $userId)
    {
        $user = User::findOrFail($userId);

        if (!$user->foto_profile || !Storage::disk('public')->exists($user->foto_profile)) {
            return response()->json(['message' => 'Foto profil tidak ditemukan.'], 404);
        }

        $filePath = Storage::disk('public')->path($user->foto_profile);
        $mimeType = mime_content_type($filePath) ?: 'image/jpeg';

        return response()->file($filePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
