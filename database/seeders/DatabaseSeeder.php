<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ShiftSeeder::class,
            AdminSeeder::class,
            ChecklistVerificationHierarchySeeder::class, // hierarki Supervisor (aman diulang; tidak membuat akun tanpa username/email di .env)
        ]);
    }
}
