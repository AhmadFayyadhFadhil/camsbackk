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
    }
}
