<?php

namespace Database\Seeders;

use App\Enums\RoleEnum;
use App\Enums\SupervisorLevelEnum;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Menyiapkan hierarki Supervisor untuk workflow verifikasi Checklist Harian:
 *   Pak Winaryo (head) > Pak Kusnan (verifier) ; Pak Denny (operational) sejajar Pak Kusnan tanpa hak approval.
 *
 * - Memakai akun existing bila ada (cocok via username, email, atau nama lengkap yang unik).
 * - Membuat akun baru HANYA bila username dan email diisi lewat config/.env (tidak ada nilai karangan).
 *   Password dari env; jika kosong dibuat acak dan ditampilkan sekali di konsol.
 * - Tidak menghapus / menimpa data existing; aman dijalankan berulang.
 *
 * Jalankan:  php artisan db:seed --class=ChecklistVerificationHierarchySeeder
 */
class ChecklistVerificationHierarchySeeder extends Seeder
{
    public function run(): void
    {
        $supervisorRole = Role::firstOrCreate(
            ['name' => RoleEnum::SUPERVISOR->value],
            ['description' => 'System role for Supervisor']
        );

        $map = [
            SupervisorLevelEnum::HEAD->value => 'head',
            SupervisorLevelEnum::VERIFIER->value => 'verifier',
            SupervisorLevelEnum::OPERATIONAL->value => 'operational',
        ];

        foreach ($map as $level => $configKey) {
            $cfg = config("cams.verification.supervisors.{$configKey}", []);
            $user = $this->findExisting($cfg);

            if (!$user) {
                $user = $this->createIfConfigured($cfg, $configKey);
            }

            if (!$user) {
                continue;
            }

            // Pastikan memiliki role supervisor (role existing, tidak membuat role baru)
            UserRole::firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $supervisorRole->id,
            ]);

            // Satu akun aktif per level head/verifier: jangan mengambil alih bila sudah dipegang akun lain
            if (in_array($level, ['head', 'verifier'], true)) {
                $holder = User::where('supervisor_level', $level)->where('id', '!=', $user->id)->where('is_active', true)->first();
                if ($holder) {
                    $this->command?->warn("Level '{$level}' sudah dipegang {$holder->full_name}; {$user->full_name} dilewati.");
                    continue;
                }
            }

            $user->update(['supervisor_level' => $level]);
            $this->command?->info("{$user->full_name} ({$user->username}) => level '{$level}'.");
        }
    }

    private function findExisting(array $cfg): ?User
    {
        if (!empty($cfg['username'])) {
            $u = User::where('username', $cfg['username'])->first();
            if ($u) {
                return $u;
            }
        }
        if (!empty($cfg['email'])) {
            $u = User::where('email', $cfg['email'])->first();
            if ($u) {
                return $u;
            }
        }
        if (!empty($cfg['full_name'])) {
            // Hanya dipakai bila tepat satu akun supervisor yang cocok, untuk menghindari salah pasang
            $matches = User::where('full_name', 'like', '%' . $cfg['full_name'] . '%')
                ->whereHas('roles', fn ($q) => $q->where('name', RoleEnum::SUPERVISOR->value))
                ->get();
            if ($matches->count() === 1) {
                return $matches->first();
            }
            if ($matches->count() > 1) {
                $this->command?->warn("Nama '{$cfg['full_name']}' cocok dengan lebih dari satu akun supervisor; isi username/email di .env.");
            }
        }
        return null;
    }

    private function createIfConfigured(array $cfg, string $key): ?User
    {
        if (empty($cfg['username']) || empty($cfg['email'])) {
            $this->command?->warn("Akun '{$key}' ({$cfg['full_name']}) tidak ditemukan dan username/email belum diisi di .env; dilewati.");
            return null;
        }

        $password = $cfg['password'] ?: Str::password(14);
        $generated = empty($cfg['password']);

        $user = User::create([
            'username' => $cfg['username'],
            'email' => $cfg['email'],
            'full_name' => $cfg['full_name'],
            'password' => Hash::make($password, ['rounds' => 12]),
            'is_active' => true,
        ]);

        $this->command?->info("Akun '{$key}' dibuat: {$user->username}.");
        if ($generated) {
            $this->command?->warn("Password awal acak untuk {$user->username}: {$password} (catat sekarang, tidak ditampilkan lagi).");
        }

        return $user;
    }
}
