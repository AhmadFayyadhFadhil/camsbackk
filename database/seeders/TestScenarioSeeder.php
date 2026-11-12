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
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\RoomAsset;
use App\Models\CleaningMaterial;
use App\Models\SubmissionMaterial;
use App\Models\SlaParameter;
use App\Models\FindingCategory;
use App\Models\Finding;
use App\Models\AdhocTask;
use App\Models\Schedule;
use App\Models\CsAssignment;
use App\Models\Task;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistResult;
use App\Models\Verification;
use App\Models\VerificationSlaRating;
use App\Enums\RoleEnum;
use App\Enums\FrequencyEnum;
use App\Enums\PriorityEnum;
use App\Enums\FindingStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\SubmissionStatusEnum;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class TestScenarioSeeder extends Seeder
{
    public function run(): void
    {
        // 0. Disable foreign key checks for clean truncation
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        DB::table('verification_sla_ratings')->truncate();
        DB::table('verifications')->truncate();
        DB::table('checklist_results')->truncate();
        DB::table('submission_materials')->truncate();
        DB::table('checklist_submissions')->truncate();
        DB::table('adhoc_tasks')->truncate();
        DB::table('findings')->truncate();
        DB::table('room_asset_audit_items')->truncate();
        DB::table('room_asset_audits')->truncate();
        DB::table('room_assets')->truncate();
        DB::table('sla_parameters')->truncate();
        DB::table('cleaning_materials')->truncate();
        DB::table('checklist_template_items')->truncate();
        DB::table('checklist_templates')->truncate();
        DB::table('tasks')->truncate();
        DB::table('cs_assignments')->truncate();
        DB::table('schedules')->truncate();
        DB::table('checklist_items')->truncate();
        DB::table('room_pic_histories')->truncate();
        DB::table('rooms')->truncate();
        DB::table('building_shifts')->truncate();
        DB::table('buildings')->truncate();
        DB::table('user_roles')->truncate();
        DB::table('users')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        // Ensure Core Roles Exist
        $adminRole = Role::firstOrCreate(['name' => RoleEnum::ADMIN->value], ['description' => 'System role for Admin']);
        $supervisorRole = Role::firstOrCreate(['name' => RoleEnum::SUPERVISOR->value], ['description' => 'System role for Supervisor']);
        $picRole = Role::firstOrCreate(['name' => RoleEnum::PIC->value], ['description' => 'System role for PIC Ruangan']);
        $csRole = Role::firstOrCreate(['name' => RoleEnum::CS->value], ['description' => 'System role for Cleaning Service']);
        $guestRole = Role::firstOrCreate(['name' => RoleEnum::GUEST->value], ['description' => 'System role for Guest / Pelapor Kerusakan']);

        // Ensure Core Shifts Exist
        $shiftS1 = Shift::firstOrCreate(['kode_shift' => 'S1'], [
            'nama_shift' => 'Shift 1',
            'jam_mulai' => '06:00:00',
            'jam_selesai' => '14:00:00',
            'is_overnight' => false,
            'is_active' => true,
        ]);
        $shiftS2 = Shift::firstOrCreate(['kode_shift' => 'S2'], [
            'nama_shift' => 'Shift 2',
            'jam_mulai' => '14:00:00',
            'jam_selesai' => '22:00:00',
            'is_overnight' => false,
            'is_active' => true,
        ]);
        $shiftS3 = Shift::firstOrCreate(['kode_shift' => 'S3'], [
            'nama_shift' => 'Shift 3',
            'jam_mulai' => '22:00:00',
            'jam_selesai' => '06:00:00',
            'is_overnight' => true,
            'is_active' => true,
        ]);
        $shiftSN = Shift::firstOrCreate(['kode_shift' => 'SN'], [
            'nama_shift' => 'Shift Normal',
            'jam_mulai' => '07:30:00',
            'jam_selesai' => '16:30:00',
            'is_overnight' => false,
            'is_active' => true,
        ]);

        // 1. Create Standard Users with Verified Passwords
        $admin = User::create([
            'username' => 'admin',
            'email' => 'admin@cams.com',
            'full_name' => 'System Admin',
            'password' => Hash::make('AdminPass123!', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $admin->id, 'role_id' => $adminRole->id]);

        $supervisor = User::create([
            'username' => 'supervisor',
            'email' => 'supervisor@cams.com',
            'full_name' => 'Hendra Supervisor',
            'password' => Hash::make('password', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $supervisor->id, 'role_id' => $supervisorRole->id]);

        $picFerry = User::create([
            'username' => 'pic_ferry',
            'email' => 'ferry@cams.com',
            'full_name' => 'Ferry PIC',
            'password' => Hash::make('password', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $picFerry->id, 'role_id' => $picRole->id]);

        $picAnto = User::create([
            'username' => 'pic_anto',
            'email' => 'anto@cams.com',
            'full_name' => 'Anto PIC',
            'password' => Hash::make('password', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $picAnto->id, 'role_id' => $picRole->id]);

        $csBudi = User::create([
            'username' => 'cs_budi',
            'email' => 'budi@cams.com',
            'full_name' => 'Budi CS',
            'password' => Hash::make('password', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $csBudi->id, 'role_id' => $csRole->id]);

        $csAni = User::create([
            'username' => 'cs_ani',
            'email' => 'ani@cams.com',
            'full_name' => 'Ani CS',
            'password' => Hash::make('password', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $csAni->id, 'role_id' => $csRole->id]);

        $pelapor = User::create([
            'username' => 'pelapor_fasilitas',
            'email' => 'pelapor@cams.com',
            'full_name' => 'Pelapor Fasilitas (Karyawan / Tamu)',
            'password' => Hash::make('Pass123', ['rounds' => 12]),
            'is_active' => true,
        ]);
        UserRole::create(['user_id' => $pelapor->id, 'role_id' => $guestRole->id]);

        // 2. Create Buildings with Real GPS Coordinates & Geofences (Pandaan Industrial Area)
        $gpa = Building::create([
            'nama_gedung' => 'Gedung Produksi A',
            'kode_gedung' => 'GPA',
            'alamat' => 'Gedung Produksi Utama Sektor A, Pandaan',
            'latitude' => -7.64321200,
            'longitude' => 112.69876500,
            'radius_meter' => 250,
            'is_active' => true,
        ]);

        $gpb = Building::create([
            'nama_gedung' => 'Gedung Produksi B',
            'kode_gedung' => 'GPB',
            'alamat' => 'Gedung Produksi Sektor B (Cleanroom), Pandaan',
            'latitude' => -7.64415000,
            'longitude' => 112.69950000,
            'radius_meter' => 250,
            'is_active' => true,
        ]);

        // Attach Shifts to Buildings
        $gpa->shifts()->attach([$shiftS1->id, $shiftS2->id, $shiftSN->id]);
        $gpb->shifts()->attach([$shiftS1->id, $shiftS2->id, $shiftSN->id]);

        // 3. Create Rooms with Real GPS Coordinates, Floor, Radius & Assigned PIC
        $roomKoridor = Room::create([
            'id' => (string) Str::uuid(),
            'building_id' => $gpa->id,
            'kode_ruangan' => 'KOR-BAR',
            'nama_ruangan' => 'Koridor Barat',
            'lantai' => '1',
            'latitude' => -7.64315000,
            'longitude' => 112.69865000,
            'radius_meter' => 25,
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
            'latitude' => -7.64328000,
            'longitude' => 112.69872000,
            'radius_meter' => 20,
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
            'latitude' => -7.64310000,
            'longitude' => 112.69885000,
            'radius_meter' => 15,
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
            'latitude' => -7.64335000,
            'longitude' => 112.69890000,
            'radius_meter' => 15,
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
            'latitude' => -7.64415000,
            'longitude' => 112.69950000,
            'radius_meter' => 20,
            'pic_user_id' => $picAnto->id,
            'qr_code_token' => (string) Str::uuid(),
            'is_active' => true,
        ]);

        // Generate QR codes and create PIC history for rooms using QrCodeService
        $qrService = resolve(\App\Services\QrCodeService::class);
        foreach ([$roomKoridor, $roomLoker, $roomPantry, $roomToilet, $roomSteril] as $room) {
            $qrBinary = $qrService->generate($room->id, $room->qr_code_token, $room->building_id);
            $room->update(['qr_code_image' => $qrBinary]);

            if ($room->pic_user_id) {
                DB::table('room_pic_histories')->insert([
                    'id' => (string) Str::uuid(),
                    'room_id' => $room->id,
                    'user_id' => $room->pic_user_id,
                    'tanggal_mulai' => today()->subDays(30)->toDateString(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 4. Create Cleaning Materials (Master Bahan Kimia & Alat Kerja)
        $materials = [
            ['nama_material' => 'Wipol Karbol Wangi Pinus', 'jenis' => 'chemical', 'kode_material' => 'MAT-CHM-01'],
            ['nama_material' => 'Porstex Pembersih Keramik & Porselen', 'jenis' => 'chemical', 'kode_material' => 'MAT-CHM-02'],
            ['nama_material' => 'Mr. Muscle Pembersih Kaca', 'jenis' => 'chemical', 'kode_material' => 'MAT-CHM-03'],
            ['nama_material' => 'SOS Sabun Cuci Tangan Anti-Bakteri', 'jenis' => 'chemical', 'kode_material' => 'MAT-CHM-04'],
            ['nama_material' => 'Alkohol Disinfektan 70% Steril', 'jenis' => 'chemical', 'kode_material' => 'MAT-CHM-05'],
            ['nama_material' => 'Kain Lap Microfiber Halus', 'jenis' => 'tool', 'kode_material' => 'MAT-TOOL-01'],
            ['nama_material' => 'Sapu Nilon & Pengki Plastik', 'jenis' => 'tool', 'kode_material' => 'MAT-TOOL-02'],
            ['nama_material' => 'Mop Pel Lantai & Ember Peras', 'jenis' => 'tool', 'kode_material' => 'MAT-TOOL-03'],
            ['nama_material' => 'Sikat Kloset Handle Panjang', 'jenis' => 'tool', 'kode_material' => 'MAT-TOOL-04'],
        ];
        $matMap = [];
        foreach ($materials as $m) {
            $createdMat = CleaningMaterial::create([
                'nama_material' => $m['nama_material'],
                'jenis' => $m['jenis'],
                'kode_material' => $m['kode_material'],
                'is_active' => true,
            ]);
            $matMap[$m['kode_material']] = $createdMat;
        }

        // 5. Create Room Assets (Master Aset & Peralatan Ruangan)
        $assetsData = [
            ['room_id' => $roomKoridor->id, 'nama_aset' => 'Lampu LED Philips 18W', 'kode_aset' => 'AST-KB-01', 'jumlah' => 4],
            ['room_id' => $roomKoridor->id, 'nama_aset' => 'Railing Tangga Stainless 304', 'kode_aset' => 'AST-KB-02', 'jumlah' => 2],
            ['room_id' => $roomKoridor->id, 'nama_aset' => 'Tempat Sampah 3 Warna Pedal', 'kode_aset' => 'AST-KB-03', 'jumlah' => 1],
            ['room_id' => $roomPantry->id, 'nama_aset' => 'Meja Makan Kayu 4 Kursi', 'kode_aset' => 'AST-PN-01', 'jumlah' => 2],
            ['room_id' => $roomPantry->id, 'nama_aset' => 'Wastafel Cuci Piring Stainless', 'kode_aset' => 'AST-PN-02', 'jumlah' => 1],
            ['room_id' => $roomPantry->id, 'nama_aset' => 'Dispenser Air Miyako Hot/Cold', 'kode_aset' => 'AST-PN-03', 'jumlah' => 1],
            ['room_id' => $roomPantry->id, 'nama_aset' => 'Kulkas Sharp 2 Pintu Inverter', 'kode_aset' => 'AST-PN-04', 'jumlah' => 1],
            ['room_id' => $roomToilet->id, 'nama_aset' => 'Kloset Duduk Toto EcoFlush', 'kode_aset' => 'AST-KM-01', 'jumlah' => 2],
            ['room_id' => $roomToilet->id, 'nama_aset' => 'Wastafel Toto Keramik Putih', 'kode_aset' => 'AST-KM-02', 'jumlah' => 2],
            ['room_id' => $roomToilet->id, 'nama_aset' => 'Cermin Dinding Bevel 60x80', 'kode_aset' => 'AST-KM-03', 'jumlah' => 2],
            ['room_id' => $roomToilet->id, 'nama_aset' => 'Exhaust Fan Panasonic 10 Inch', 'kode_aset' => 'AST-KM-04', 'jumlah' => 1],
            ['room_id' => $roomLoker->id, 'nama_aset' => 'Lemari Loker Karyawan 18 Pintu', 'kode_aset' => 'AST-LK-01', 'jumlah' => 2],
            ['room_id' => $roomLoker->id, 'nama_aset' => 'Bangku Panjang Kayu Mahoni', 'kode_aset' => 'AST-LK-02', 'jumlah' => 2],
            ['room_id' => $roomLoker->id, 'nama_aset' => 'AC Daikin Inverter 1.5 PK', 'kode_aset' => 'AST-LK-03', 'jumlah' => 1],
            ['room_id' => $roomSteril->id, 'nama_aset' => 'Mesin Autoclave Hirayama HV-50', 'kode_aset' => 'AST-ST-01', 'jumlah' => 1],
            ['room_id' => $roomSteril->id, 'nama_aset' => 'Meja Kerja Stainless Steel 304', 'kode_aset' => 'AST-ST-02', 'jumlah' => 2],
            ['room_id' => $roomSteril->id, 'nama_aset' => 'Air Shower Entry Chamber', 'kode_aset' => 'AST-ST-03', 'jumlah' => 1],
            ['room_id' => $roomSteril->id, 'nama_aset' => 'Lampu Sterilisasi UV Germicidal', 'kode_aset' => 'AST-ST-04', 'jumlah' => 2],
        ];
        $assetMap = [];
        foreach ($assetsData as $ast) {
            $createdAst = RoomAsset::create([
                'room_id' => $ast['room_id'],
                'nama_aset' => $ast['nama_aset'],
                'kode_aset' => $ast['kode_aset'],
                'jumlah' => $ast['jumlah'],
                'status' => 'active',
            ]);
            $assetMap[$ast['kode_aset']] = $createdAst;
        }

        // 6. Create Checklist Templates & Items (Katalog Master Template Checklist)
        $tplOffice = ChecklistTemplate::create([
            'nama_template' => 'SOP Kebersihan Area Kantor & Koridor',
            'deskripsi' => 'Prosedur standar kebersihan area umum, lorong koridor, dan ruang kerja kantor.',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplOffice->id,
            'nama_item' => 'Menyapu dan mengepel lantai koridor barat',
            'deskripsi' => 'Pastikan lantai bersih dari debu, kotoran, dan tidak licin.',
            'frekuensi' => 'harian',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplOffice->id,
            'nama_item' => 'Membersihkan jendela kaca & railing tangga',
            'deskripsi' => 'Gunakan pembersih kaca dan lap microfiber.',
            'frekuensi' => 'harian',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplOffice->id,
            'nama_item' => 'Mengelap meja perabot & dustbin',
            'deskripsi' => 'Bersihkan debu pada permukaan meja dan rapikan posisi perabot.',
            'frekuensi' => 'harian',
        ]);

        $tplSanitasi = ChecklistTemplate::create([
            'nama_template' => 'SOP Sanitasi & Higienitas Toilet & Pantry',
            'deskripsi' => 'Prosedur sanitasi area basah toilet dan dapur pantry karyawan.',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplSanitasi->id,
            'nama_item' => 'Membersihkan meja pantry & wastafel',
            'deskripsi' => 'Cuci bak cuci piring dan bersihkan remah makanan.',
            'frekuensi' => 'harian',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplSanitasi->id,
            'nama_item' => 'Membuang sampah dan mengganti plastik tempat sampah',
            'deskripsi' => 'Ikat kantong sampah lama dan pasang kantong baru.',
            'frekuensi' => 'harian',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplSanitasi->id,
            'nama_item' => 'Menggosok kloset, wastafel & lantai kamar mandi',
            'deskripsi' => 'Sikat kloset dan wastafel menggunakan cairan pembersih porselen.',
            'frekuensi' => 'harian',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplSanitasi->id,
            'nama_item' => 'Isi ulang sabun cuci tangan & tisu toilet',
            'deskripsi' => 'Cek dispenser sabun dan pastikan roll tisu terisi.',
            'frekuensi' => 'harian',
        ]);

        $tplCleanroom = ChecklistTemplate::create([
            'nama_template' => 'SOP Sterilisasi Cleanroom & Laboratorium',
            'deskripsi' => 'Protokol higienitas GMP untuk ruang steril dan ruang produksi farmasi.',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplCleanroom->id,
            'nama_item' => 'Melakukan disinfeksi meja & permukaan alat sterilisasi',
            'deskripsi' => 'Semprot alkohol 70% dan lap secara searah.',
            'frekuensi' => 'harian',
        ]);
        ChecklistTemplateItem::create([
            'checklist_template_id' => $tplCleanroom->id,
            'nama_item' => 'Sanitasi lantai epoxy cleanroom',
            'deskripsi' => 'Mop lantai dengan disinfektan non-korosif khusus cleanroom.',
            'frekuensi' => 'harian',
        ]);

        // 7. Create SLA Parameters (Indikator Mutu Penilaian Kebersihan)
        $slaParams = [
            ['nama_parameter' => 'Kebersihan & Kilap Lantai', 'deskripsi' => 'Tingkat kebersihan permukaan lantai dari noda, debu, dan residu kotoran', 'tipe_penilaian' => 'scale_1_5'],
            ['nama_parameter' => 'Kerapian Area & Penataan Perabot', 'deskripsi' => 'Penataan kursi, meja, dispenser, dan perabot rapi pada tempatnya', 'tipe_penilaian' => 'scale_1_5'],
            ['nama_parameter' => 'Kondisi Sanitasi & Higienis Wastafel/Kloset', 'deskripsi' => 'Kloset, urinal, dan wastafel bersih higienis dan tidak berkerak', 'tipe_penilaian' => 'scale_1_5'],
            ['nama_parameter' => 'Ketersediaan Consumable (Sabun/Tisu/Plastik)', 'deskripsi' => 'Dispenser sabun terisi, tisu tersedia, dan kantong sampah terpasang', 'tipe_penilaian' => 'yes_no'],
            ['nama_parameter' => 'Bebas Bau Tidak Sedap & Sirkulasi Segar', 'deskripsi' => 'Ruangan tidak berbau apek/tidak sedap dan sirkulasi udara baik', 'tipe_penilaian' => 'yes_no'],
        ];
        $slaMap = [];
        foreach ($slaParams as $sp) {
            $createdSla = SlaParameter::create([
                'nama_parameter' => $sp['nama_parameter'],
                'deskripsi' => $sp['deskripsi'],
                'tipe_penilaian' => $sp['tipe_penilaian'],
                'is_active' => true,
            ]);
            $slaMap[] = $createdSla;
        }

        // 8. Create Checklist Items
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
        $itemSteril1 = ChecklistItem::create([
            'nama_item' => 'Melakukan disinfeksi meja & permukaan alat sterilisasi',
            'kategori' => 'Ruang Sterilisasi',
            'is_active' => true,
            'created_by' => $supervisor->id,
        ]);

        // 9. Create Master Schedules
        $schKB1 = Schedule::create(['room_id' => $roomKoridor->id, 'checklist_item_id' => $itemKB1->id, 'shift_id' => $shiftS1->id, 'frekuensi' => FrequencyEnum::HARIAN, 'is_active' => true]);
        $schKB2 = Schedule::create(['room_id' => $roomKoridor->id, 'checklist_item_id' => $itemKB2->id, 'shift_id' => $shiftS1->id, 'frekuensi' => FrequencyEnum::HARIAN, 'is_active' => true]);
        $schPantry1 = Schedule::create(['room_id' => $roomPantry->id, 'checklist_item_id' => $itemPantry1->id, 'shift_id' => $shiftSN->id, 'frekuensi' => FrequencyEnum::HARIAN, 'is_active' => true]);
        $schPantry2 = Schedule::create(['room_id' => $roomPantry->id, 'checklist_item_id' => $itemPantry2->id, 'shift_id' => $shiftSN->id, 'frekuensi' => FrequencyEnum::HARIAN, 'is_active' => true]);
        $schToilet1 = Schedule::create(['room_id' => $roomToilet->id, 'checklist_item_id' => $itemToilet1->id, 'shift_id' => $shiftS2->id, 'frekuensi' => FrequencyEnum::HARIAN, 'is_active' => true]);
        $schToilet2 = Schedule::create(['room_id' => $roomToilet->id, 'checklist_item_id' => $itemToilet2->id, 'shift_id' => $shiftS2->id, 'frekuensi' => FrequencyEnum::HARIAN, 'is_active' => true]);
        $schSteril1 = Schedule::create(['room_id' => $roomSteril->id, 'checklist_item_id' => $itemSteril1->id, 'shift_id' => $shiftSN->id, 'frekuensi' => FrequencyEnum::HARIAN, 'is_active' => true]);

        // 10. Create CS Assignments
        CsAssignment::create(['cs_user_id' => $csBudi->id, 'building_id' => $gpa->id, 'shift_id' => $shiftS1->id, 'tanggal_mulai' => today()->subDays(7)->toDateString()]);
        CsAssignment::create(['cs_user_id' => $csBudi->id, 'building_id' => $gpa->id, 'shift_id' => $shiftS2->id, 'tanggal_mulai' => today()->subDays(7)->toDateString()]);
        CsAssignment::create(['cs_user_id' => $csAni->id, 'building_id' => $gpa->id, 'shift_id' => $shiftSN->id, 'tanggal_mulai' => today()->subDays(7)->toDateString()]);
        CsAssignment::create(['cs_user_id' => $csAni->id, 'building_id' => $gpb->id, 'shift_id' => $shiftSN->id, 'tanggal_mulai' => today()->subDays(7)->toDateString()]);

        // 11. Create Sample Findings (Temuan Kerusakan)
        $catPlumbing = FindingCategory::where('kode_kategori', 'PLUMB')->first() ?? FindingCategory::first();
        $catElectrical = FindingCategory::where('kode_kategori', 'ELEC')->first() ?? FindingCategory::first();

        Finding::create([
            'room_id' => $roomPantry->id,
            'room_asset_id' => $assetMap['AST-PN-02']->id ?? null,
            'finding_category_id' => $catPlumbing?->id,
            'reported_by' => $pelapor->id,
            'deskripsi' => 'Kran air wastafel cuci piring menetes terus (bocor halus) dan tuas pemutar terasa longgar.',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::OPEN,
            'deadline_perbaikan' => today()->addDays(2)->toDateString(),
        ]);

        Finding::create([
            'room_id' => $roomKoridor->id,
            'room_asset_id' => $assetMap['AST-KB-01']->id ?? null,
            'finding_category_id' => $catElectrical?->id,
            'reported_by' => $csBudi->id,
            'assigned_to' => $picFerry->id,
            'assigned_at' => Carbon::now()->subHours(3),
            'response_time_minutes' => 45,
            'deskripsi' => 'Lampu LED ceiling sisi barat berkedip saat saklar dinyalakan.',
            'prioritas' => PriorityEnum::LOW,
            'status' => FindingStatusEnum::IN_PROGRESS,
            'deadline_perbaikan' => today()->addDays(3)->toDateString(),
        ]);

        Finding::create([
            'room_id' => $roomToilet->id,
            'room_asset_id' => $assetMap['AST-KM-01']->id ?? null,
            'finding_category_id' => $catPlumbing?->id,
            'reported_by' => $picFerry->id,
            'assigned_to' => $picFerry->id,
            'assigned_at' => Carbon::now()->subDays(1),
            'response_time_minutes' => 20,
            'deskripsi' => 'Handle flush kloset no 1 tersangkut dan air sempat mengalir terus.',
            'prioritas' => PriorityEnum::HIGH,
            'status' => FindingStatusEnum::RESOLVED,
            'resolved_at' => Carbon::now()->subHours(2),
            'deadline_perbaikan' => today()->toDateString(),
        ]);

        // 12. Create Sample Adhoc Tasks (Tugas Khusus / Acara)
        AdhocTask::create([
            'created_by' => $supervisor->id,
            'cs_user_id' => $csAni->id,
            'room_id' => $roomSteril->id,
            'judul' => 'Deep Cleaning & Disinfeksi Menjelang Audit Eksternal BPOM',
            'deskripsi' => 'Lakukan disinfeksi menyeluruh area lantai epoxy, dinding panel, meja autoclave, dan ruang antara cleanroom.',
            'priority' => 'high',
            'task_type' => 'immediate',
            'requires_cleanup' => false,
            'due_datetime' => Carbon::today()->setTime(16, 0, 0),
            'status' => 'pending',
            'stage' => 'pending',
            'checklist_items' => [
                ['text' => 'Disinfeksi dinding panel dan kaca pengamat', 'is_done' => false],
                ['text' => 'Mop lantai dengan cairan alkohol 70%', 'is_done' => false],
                ['text' => 'Pembersihan filter luar air shower chamber', 'is_done' => false],
            ],
        ]);

        AdhocTask::create([
            'created_by' => $supervisor->id,
            'cs_user_id' => $csBudi->id,
            'room_id' => $roomKoridor->id,
            'judul' => 'Setup Area & Kebersihan Acara Townhall Bulanan Karyawan',
            'deskripsi' => 'Penataan 50 kursi lipat, pemasangan standing banner, dan pembersihan area selasar setelah acara.',
            'priority' => 'medium',
            'task_type' => 'scheduled_event',
            'requires_cleanup' => true,
            'event_start_time' => Carbon::today()->setTime(10, 0, 0),
            'due_datetime' => Carbon::today()->setTime(15, 0, 0),
            'status' => 'verified',
            'stage' => 'completed',
            'started_at' => Carbon::today()->setTime(8, 0, 0),
            'setup_submitted_at' => Carbon::today()->setTime(9, 30, 0),
            'cleanup_submitted_at' => Carbon::today()->setTime(13, 0, 0),
            'verified_at' => Carbon::today()->setTime(13, 30, 0),
            'verification_notes' => 'Setup dan kebersihan pasca-acara rapi sempurna.',
            'checklist_items' => [
                ['text' => 'Penataan kursi dan meja registrasi', 'is_done' => true],
                ['text' => 'Penyediaan tempat sampah tambahan', 'is_done' => true],
                ['text' => 'Sapu dan pel lantai setelah acara selesai', 'is_done' => true],
            ],
        ]);

        // 13. Create Tasks For Today (With Sample Completed, Submitted, and Pending Tasks)
        // Task 1: Koridor Barat - Menyapu dan mengepel (Shift S1, Budi CS) -> COMPLETED & VERIFIED
        $task1 = Task::create([
            'room_id' => $roomKoridor->id,
            'schedule_id' => $schKB1->id,
            'cs_user_id' => $csBudi->id,
            'shift_id' => $shiftS1->id,
            'tanggal_task' => today()->toDateString(),
            'due_datetime' => Carbon::today()->setTime(14, 0, 0),
            'status' => TaskStatusEnum::COMPLETED,
        ]);

        // Create Submission & Verification for Task 1
        $sub1 = ChecklistSubmission::create([
            'task_id' => $task1->id,
            'cs_user_id' => $csBudi->id,
            'submitted_at' => Carbon::today()->setTime(8, 30, 0),
            'resubmit_count' => 0,
            'scan_token_used' => $roomKoridor->qr_code_token,
            'catatan_cs' => 'Lantai koridor telah disapu, dipel dengan karbol wangi, dan dikeringkan.',
            'status' => SubmissionStatusEnum::APPROVED,
            'latitude' => -7.64315200,
            'longitude' => 112.69865100,
            'gps_accuracy' => 12.5,
            'gps_captured_at' => Carbon::today()->setTime(8, 29, 50),
        ]);

        ChecklistResult::create([
            'submission_id' => $sub1->id,
            'checklist_item_id' => $itemKB1->id,
            'is_done' => true,
            'catatan' => 'Selesai sesuai SOP',
        ]);

        if (isset($matMap['MAT-CHM-01'])) {
            DB::table('submission_materials')->insert([
                'id' => (string) Str::uuid(),
                'submission_id' => $sub1->id,
                'cleaning_material_id' => $matMap['MAT-CHM-01']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if (isset($matMap['MAT-TOOL-03'])) {
            DB::table('submission_materials')->insert([
                'id' => (string) Str::uuid(),
                'submission_id' => $sub1->id,
                'cleaning_material_id' => $matMap['MAT-TOOL-03']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $verif1 = Verification::create([
            'submission_id' => $sub1->id,
            'verified_by' => $supervisor->id,
            'role_verifier' => 'supervisor',
            'status' => 'approved',
            'catatan_perbaikan' => 'Lantai koridor bersih mengkilap, wangi segar, dan bebas jejak kotoran.',
            'qr_scanned_at' => Carbon::today()->setTime(9, 15, 0),
            'latitude' => -7.64315100,
            'longitude' => 112.69865200,
            'is_onsite_verified' => true,
            'verified_at' => Carbon::today()->setTime(9, 16, 0),
        ]);

        // Add SLA Ratings for verif1
        foreach ($slaMap as $sla) {
            VerificationSlaRating::create([
                'verification_id' => $verif1->id,
                'sla_parameter_id' => $sla->id,
                'nilai' => $sla->tipe_penilaian === 'scale_1_5' ? '5' : 'yes',
            ]);
        }

        // Task 2: Pantry - Membersihkan meja pantry & wastafel (Shift SN, Ani CS) -> WAITING_VERIFICATION (Pending in Supervisor Verification Queue)
        $task2 = Task::create([
            'room_id' => $roomPantry->id,
            'schedule_id' => $schPantry1->id,
            'cs_user_id' => $csAni->id,
            'shift_id' => $shiftSN->id,
            'tanggal_task' => today()->toDateString(),
            'due_datetime' => Carbon::today()->setTime(16, 30, 0),
            'status' => TaskStatusEnum::WAITING_VERIFICATION,
        ]);

        $sub2 = ChecklistSubmission::create([
            'task_id' => $task2->id,
            'cs_user_id' => $csAni->id,
            'submitted_at' => Carbon::today()->setTime(9, 45, 0),
            'resubmit_count' => 0,
            'scan_token_used' => $roomPantry->qr_code_token,
            'catatan_cs' => 'Meja pantry sudah dibersihkan dan wastafel telah disikat bersih.',
            'status' => SubmissionStatusEnum::SUBMITTED,
            'latitude' => -7.64310200,
            'longitude' => 112.69884800,
            'gps_accuracy' => 10.0,
            'gps_captured_at' => Carbon::today()->setTime(9, 44, 45),
        ]);

        ChecklistResult::create([
            'submission_id' => $sub2->id,
            'checklist_item_id' => $itemPantry1->id,
            'is_done' => true,
            'catatan' => 'Selesai dibersihkan',
        ]);

        if (isset($matMap['MAT-CHM-04'])) {
            DB::table('submission_materials')->insert([
                'id' => (string) Str::uuid(),
                'submission_id' => $sub2->id,
                'cleaning_material_id' => $matMap['MAT-CHM-04']->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Task 3: Koridor Barat - Membersihkan jendela (Shift S1, Budi CS) -> PENDING
        Task::create([
            'room_id' => $roomKoridor->id,
            'schedule_id' => $schKB2->id,
            'cs_user_id' => $csBudi->id,
            'shift_id' => $shiftS1->id,
            'tanggal_task' => today()->toDateString(),
            'due_datetime' => Carbon::today()->setTime(14, 0, 0),
            'status' => TaskStatusEnum::PENDING,
        ]);

        // Task 4: Pantry - Membuang sampah (Shift SN, Ani CS) -> PENDING
        Task::create([
            'room_id' => $roomPantry->id,
            'schedule_id' => $schPantry2->id,
            'cs_user_id' => $csAni->id,
            'shift_id' => $shiftSN->id,
            'tanggal_task' => today()->toDateString(),
            'due_datetime' => Carbon::today()->setTime(16, 30, 0),
            'status' => TaskStatusEnum::PENDING,
        ]);

        // Task 5: Toilet - Menggosok kloset (Shift S2, Budi CS) -> PENDING
        Task::create([
            'room_id' => $roomToilet->id,
            'schedule_id' => $schToilet1->id,
            'cs_user_id' => $csBudi->id,
            'shift_id' => $shiftS2->id,
            'tanggal_task' => today()->toDateString(),
            'due_datetime' => Carbon::today()->setTime(22, 0, 0),
            'status' => TaskStatusEnum::PENDING,
        ]);

        // Task 6: Toilet - Isi ulang sabun & tisu (Shift S2, Budi CS) -> PENDING
        Task::create([
            'room_id' => $roomToilet->id,
            'schedule_id' => $schToilet2->id,
            'cs_user_id' => $csBudi->id,
            'shift_id' => $shiftS2->id,
            'tanggal_task' => today()->toDateString(),
            'due_datetime' => Carbon::today()->setTime(22, 0, 0),
            'status' => TaskStatusEnum::PENDING,
        ]);

        // Task 7: Sterilisasi - Disinfeksi meja (Shift SN, Ani CS) -> PENDING
        Task::create([
            'room_id' => $roomSteril->id,
            'schedule_id' => $schSteril1->id,
            'cs_user_id' => $csAni->id,
            'shift_id' => $shiftSN->id,
            'tanggal_task' => today()->toDateString(),
            'due_datetime' => Carbon::today()->setTime(16, 30, 0),
            'status' => TaskStatusEnum::PENDING,
        ]);
    }
}
