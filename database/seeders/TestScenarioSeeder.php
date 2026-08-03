<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use App\Models\UserRole;
use App\Models\Building;
use App\Models\Room;
use App\Models\Shift;
use App\Models\ChecklistItem;
use App\Models\Schedule;
use App\Models\CsAssignment;
use App\Enums\RoleEnum;
use App\Enums\FrequencyEnum;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TestScenarioSeeder extends Seeder
{
    public function run(): void
    {
        // Disable foreign key checks for clean truncation
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        // Keep roles, shifts, and admin (we'll just clear test data)
        CsAssignment::truncate();
        Schedule::truncate();
        ChecklistItem::truncate();
        Room::truncate();
        Building::truncate();
        DB::table('room_pic_histories')->truncate();
        
        // Truncate users except admin
        $adminUser = User::where('username', 'admin')->first();
        if ($adminUser) {
            User::where('id', '!=', $adminUser->id)->delete();
            UserRole::where('user_id', '!=', $adminUser->id)->delete();
        } else {
            User::truncate();
            UserRole::truncate();
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Get Role IDs
        $supervisorRole = Role::where('name', RoleEnum::SUPERVISOR->value)->first();
        $picRole = Role::where('name', RoleEnum::PIC->value)->first();
        $csRole = Role::where('name', RoleEnum::CS->value)->first();

        // 1. Create Users
        $supervisor = User::create([
            'username' => 'supervisor',
            'email' => 'supervisor@cams.com',
            'full_name' => 'Hendra Supervisor',
            'password' => Hash::make('Password123!', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $supervisor->id, 'role_id' => $supervisorRole->id]);

        $picFerry = User::create([
            'username' => 'pic_ferry',
            'email' => 'ferry@cams.com',
            'full_name' => 'Ferry PIC',
            'password' => Hash::make('Password123!', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $picFerry->id, 'role_id' => $picRole->id]);

        $picAnto = User::create([
            'username' => 'pic_anto',
            'email' => 'anto@cams.com',
            'full_name' => 'Anto PIC',
            'password' => Hash::make('Password123!', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $picAnto->id, 'role_id' => $picRole->id]);

        $csBudi = User::create([
            'username' => 'cs_budi',
            'email' => 'budi@cams.com',
            'full_name' => 'Budi CS',
            'password' => Hash::make('Password123!', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $csBudi->id, 'role_id' => $csRole->id]);

        $csAni = User::create([
            'username' => 'cs_ani',
            'email' => 'ani@cams.com',
            'full_name' => 'Ani CS',
            'password' => Hash::make('Password123!', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $csAni->id, 'role_id' => $csRole->id]);

        // 2. Create Buildings
        $gpa = Building::create([
            'nama_gedung' => 'Gedung Produksi A',
            'kode_gedung' => 'GPA',
            'alamat' => 'Gedung Produksi Utama Sektor A',
            'is_active' => true,
        ]);

        $gpb = Building::create([
            'nama_gedung' => 'Gedung Produksi B',
            'kode_gedung' => 'GPB',
            'alamat' => 'Gedung Produksi Sektor B (Cleanroom)',
            'is_active' => true,
        ]);

        // 3. Associate Shifts to Buildings
        $shifts = Shift::all();
        $s1 = $shifts->where('kode_shift', 'S1')->first();
        $s2 = $shifts->where('kode_shift', 'S2')->first();
        $sn = $shifts->where('kode_shift', 'SN')->first();

        $gpa->shifts()->attach([$s1->id, $s2->id, $sn->id]);
        $gpb->shifts()->attach([$s1->id, $s2->id, $sn->id]);

        // 4. Create Rooms
        $roomKoridor = Room::create([
            'id' => (string) Str::uuid(),
            'building_id' => $gpa->id,
            'kode_ruangan' => 'KOR-BAR',
            'nama_ruangan' => 'Koridor Barat',
            'lantai' => '1',
            'pic_user_id' => $picFerry->id,
            'qr_code_token' => (string) Str::uuid(),
            'is_active' => true,
        ]);

        $roomLoker = Room::create([
            'id' => (string) Str::uuid(),
            'building_id' => $gpa->id,
            'kode_ruangan' => 'LOK-A',
            'nama_ruangan' => 'Ruang Loker A',
            'lantai' => '1',
            'pic_user_id' => $picFerry->id,
            'qr_code_token' => (string) Str::uuid(),
            'is_active' => true,
        ]);

        $roomPantry = Room::create([
            'id' => (string) Str::uuid(),
            'building_id' => $gpa->id,
            'kode_ruangan' => 'PNTRY',
            'nama_ruangan' => 'Pantry',
            'lantai' => '1',
            'pic_user_id' => $picFerry->id,
            'qr_code_token' => (string) Str::uuid(),
            'is_active' => true,
        ]);

        $roomToilet = Room::create([
            'id' => (string) Str::uuid(),
            'building_id' => $gpa->id,
            'kode_ruangan' => 'kmr1',
            'nama_ruangan' => 'Kamar Mandi office 1',
            'lantai' => '1',
            'pic_user_id' => $picFerry->id,
            'qr_code_token' => (string) Str::uuid(),
            'is_active' => true,
        ]);

        $roomSteril = Room::create([
            'id' => (string) Str::uuid(),
            'building_id' => $gpb->id,
            'kode_ruangan' => 'STER-1',
            'nama_ruangan' => 'Ruang Sterilisasi',
            'lantai' => '2',
            'pic_user_id' => $picAnto->id,
            'qr_code_token' => (string) Str::uuid(),
            'is_active' => true,
        ]);

        // Generate QR codes and create PIC history for rooms using the Service
        $qrService = resolve(\App\Services\QrCodeService::class);
        foreach ([$roomKoridor, $roomLoker, $roomPantry, $roomToilet, $roomSteril] as $room) {
            $qrBinary = $qrService->generate($room->id, $room->qr_code_token, $room->building_id);
            $room->update(['qr_code_image' => $qrBinary]);

            // Create PIC History
            if ($room->pic_user_id) {
                DB::table('room_pic_histories')->insert([
                    'id' => (string) Str::uuid(),
                    'room_id' => $room->id,
                    'user_id' => $room->pic_user_id,
                    'tanggal_mulai' => today()->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 5. Create Checklist Items
        // Koridor Barat Items
        $itemKB1 = ChecklistItem::create([
            'nama_item' => 'Menyapu dan mengepel lantai koridor barat',
            'kategori' => 'Koridor Barat',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);
        $itemKB2 = ChecklistItem::create([
            'nama_item' => 'Membersihkan jendela kaca & railing tangga',
            'kategori' => 'Koridor Barat',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);

        // Pantry Items
        $itemPantry1 = ChecklistItem::create([
            'nama_item' => 'Membersihkan meja pantry & wastafel',
            'kategori' => 'Pantry',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);
        $itemPantry2 = ChecklistItem::create([
            'nama_item' => 'Membuang sampah dan mengganti plastik tempat sampah',
            'kategori' => 'Pantry',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);

        // Toilet Items
        $itemToilet1 = ChecklistItem::create([
            'nama_item' => 'Menggosok kloset, wastafel & lantai kamar mandi',
            'kategori' => 'Kamar Mandi office 1',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);
        $itemToilet2 = ChecklistItem::create([
            'nama_item' => 'Isi ulang sabun cuci tangan & tisu toilet',
            'kategori' => 'Kamar Mandi office 1',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);

        // Ruang Steril Items
        $itemSteril1 = ChecklistItem::create([
            'nama_item' => 'Melakukan disinfeksi meja & permukaan alat sterilisasi',
            'kategori' => 'Ruang Sterilisasi',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);

        // 6. Create Master Schedules
        // Koridor Barat
        Schedule::create([
            'room_id' => $roomKoridor->id,
            'checklist_item_id' => $itemKB1->id,
            'shift_id' => $s1->id,
            'frekuensi' => FrequencyEnum::HARIAN,
            'is_active' => true,
        ]);
        Schedule::create([
            'room_id' => $roomKoridor->id,
            'checklist_item_id' => $itemKB2->id,
            'shift_id' => $s1->id,
            'frekuensi' => FrequencyEnum::HARIAN,
            'is_active' => true,
        ]);

        // Pantry
        Schedule::create([
            'room_id' => $roomPantry->id,
            'checklist_item_id' => $itemPantry1->id,
            'shift_id' => $sn->id,
            'frekuensi' => FrequencyEnum::HARIAN,
            'is_active' => true,
        ]);
        Schedule::create([
            'room_id' => $roomPantry->id,
            'checklist_item_id' => $itemPantry2->id,
            'shift_id' => $sn->id,
            'frekuensi' => FrequencyEnum::HARIAN,
            'is_active' => true,
        ]);

        // Toilet
        Schedule::create([
            'room_id' => $roomToilet->id,
            'checklist_item_id' => $itemToilet1->id,
            'shift_id' => $s2->id,
            'frekuensi' => FrequencyEnum::HARIAN,
            'is_active' => true,
        ]);
        Schedule::create([
            'room_id' => $roomToilet->id,
            'checklist_item_id' => $itemToilet2->id,
            'shift_id' => $s2->id,
            'frekuensi' => FrequencyEnum::HARIAN,
            'is_active' => true,
        ]);

        // Sterilisasi
        Schedule::create([
            'room_id' => $roomSteril->id,
            'checklist_item_id' => $itemSteril1->id,
            'shift_id' => $sn->id,
            'frekuensi' => FrequencyEnum::HARIAN,
            'is_active' => true,
        ]);

        // 7. Create CS Assignments
        CsAssignment::create([
            'cs_user_id' => $csBudi->id,
            'building_id' => $gpa->id,
            'shift_id' => $s1->id,
            'tanggal_mulai' => today()->subDays(2)->toDateString(),
        ]);
        CsAssignment::create([
            'cs_user_id' => $csBudi->id,
            'building_id' => $gpa->id,
            'shift_id' => $s2->id,
            'tanggal_mulai' => today()->subDays(2)->toDateString(),
        ]);

        CsAssignment::create([
            'cs_user_id' => $csAni->id,
            'building_id' => $gpa->id,
            'shift_id' => $sn->id,
            'tanggal_mulai' => today()->subDays(2)->toDateString(),
        ]);
        CsAssignment::create([
            'cs_user_id' => $csAni->id,
            'building_id' => $gpb->id,
            'shift_id' => $sn->id,
            'tanggal_mulai' => today()->subDays(2)->toDateString(),
        ]);
    }
}
