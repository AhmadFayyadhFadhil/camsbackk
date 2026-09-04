<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use App\Enums\RoleEnum;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', RoleEnum::ADMIN->value)->first();

        if (!$adminRole) {
            $adminRole = Role::create([
                'name' => RoleEnum::ADMIN->value,
                'description' => 'System role for Admin',
            ]);
        }

        $adminUser = User::firstOrCreate(
            ['username' => 'admin'],
            [
                'email' => 'admin@cams.com',
                'full_name' => 'System Admin',
                'password' => Hash::make('AdminPass123!', ['rounds' => 12]),
                'is_active' => true,
            ]
        );

        UserRole::firstOrCreate([
            'user_id' => $adminUser->id,
            'role_id' => $adminRole->id,
        ]);

        // Seed default guest / damage reporter account
        $guestRole = Role::where('name', RoleEnum::GUEST->value)->first();
        if (!$guestRole) {
            $guestRole = Role::create([
                'name' => RoleEnum::GUEST->value,
                'description' => 'System role for Guest / Pelapor Kerusakan',
            ]);
        }

        $guestUser = User::updateOrCreate(
            ['email' => 'pelapor@cams.com'],
            [
                'username' => 'pelapor_fasilitas',
                'full_name' => 'Pelapor Fasilitas (Karyawan / Tamu)',
                'password' => Hash::make('Pass123', ['rounds' => 12]),
                'is_active' => true,
            ]
        );

        UserRole::firstOrCreate([
            'user_id' => $guestUser->id,
            'role_id' => $guestRole->id,
        ]);
    }
}
