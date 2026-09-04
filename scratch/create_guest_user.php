<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use App\Enums\RoleEnum;
use Illuminate\Support\Facades\Hash;

echo "=== CHECKING / CREATING GUEST PELAPOR USER ===\n";

$guestRole = Role::firstOrCreate(['name' => RoleEnum::GUEST->value], ['description' => 'Guest / Pelapor Kerusakan Fasilitas']);

$pelaporUser = User::where('email', 'pelapor@cams.com')->first();
if (!$pelaporUser) {
    $pelaporUser = User::create([
        'username' => 'pelapor_fasilitas',
        'full_name' => 'Pelapor Fasilitas (Karyawan / Tamu)',
        'email' => 'pelapor@cams.com',
        'password' => Hash::make('password'),
        'is_active' => true,
    ]);
    UserRole::firstOrCreate(['user_id' => $pelaporUser->id, 'role_id' => $guestRole->id]);
    echo "Created user pelapor@cams.com (password: password)\n";
} else {
    $pelaporUser->update(['is_active' => true, 'password' => Hash::make('password')]);
    UserRole::firstOrCreate(['user_id' => $pelaporUser->id, 'role_id' => $guestRole->id]);
    echo "Updated user pelapor@cams.com\n";
}

$allUsers = User::with('roles')->get();
foreach ($allUsers as $u) {
    $roles = $u->roles->pluck('name')->join(', ');
    echo "ID: {$u->id} | Name: {$u->full_name} | Email: {$u->email} | Roles: [{$roles}]\n";
}
