<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Building;
use App\Models\Room;
use App\Models\ChecklistTemplate;
use App\Models\ChecklistTemplateItem;
use App\Models\RoomAsset;
use App\Models\CleaningMaterial;
use App\Models\SlaParameter;
use App\Models\AdhocTask;
use App\Enums\RoleEnum;
use Illuminate\Http\UploadedFile;

class IndustrialFeaturesTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected User $adminUser;
    protected User $supervisorUser;
    protected User $csUser;
    protected Building $building;
    protected Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleEnum::cases() as $role) {
            Role::firstOrCreate(['name' => $role->value], ['description' => 'Role ' . $role->value]);
        }

        $this->adminUser = User::create([
            'username' => 'admin_ind',
            'email' => 'admin_ind@cams.com',
            'password' => bcrypt('AdminPass123!'),
            'full_name' => 'Administrator Ind',
            'is_active' => true,
        ]);
        $adminRole = Role::where('name', RoleEnum::ADMIN->value)->first();
        $this->adminUser->userRoles()->create(['role_id' => $adminRole->id, 'assigned_by' => $this->adminUser->id]);

        $this->supervisorUser = User::create([
            'username' => 'spv_ind',
            'email' => 'spv_ind@cams.com',
            'password' => bcrypt('SpvPass123!'),
            'full_name' => 'Supervisor Ind',
            'is_active' => true,
        ]);
        $spvRole = Role::where('name', RoleEnum::SUPERVISOR->value)->first();
        $this->supervisorUser->userRoles()->create(['role_id' => $spvRole->id, 'assigned_by' => $this->adminUser->id]);

        $this->csUser = User::create([
            'username' => 'cs_ind',
            'email' => 'cs_ind@cams.com',
            'password' => bcrypt('CsPass123!'),
            'full_name' => 'Cleaning Service Ind',
            'is_active' => true,
        ]);
        $csRole = Role::where('name', RoleEnum::CS->value)->first();
        $this->csUser->userRoles()->create(['role_id' => $csRole->id, 'assigned_by' => $this->adminUser->id]);

        $this->building = Building::create([
            'nama_gedung' => 'Pabrik Produksi Utama',
            'kode_gedung' => 'PPU-01',
            'alamat' => 'Jl. Industri No 1',
            'is_active' => true,
        ]);

        $this->room = Room::create([
            'building_id' => $this->building->id,
            'kode_ruangan' => 'R-PROD-01',
            'nama_ruangan' => 'Ruang Sterilisasi',
            'lantai' => '1',
            'is_active' => true,
            'qr_code_token' => 'qr-token-test-123',
        ]);
    }

    public function test_can_manage_checklist_templates()
    {
        $payload = [
            'nama_template' => 'Template Cleanroom Standar Industri',
            'kode_template' => 'TPL-CLEAN-01',
            'deskripsi' => 'Standar pembersihan ruangan steril ISO 8',
            'is_active' => true,
            'items' => [
                ['nama_item' => 'Sterilisasi Lantai Epoxy', 'deskripsi' => 'Gunakan alkohol 70%', 'urutan' => 1],
                ['nama_item' => 'Penyeka Dinding Panel', 'deskripsi' => 'Lap dari atas ke bawah', 'urutan' => 2],
            ]
        ];

        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson('/api/v1/checklist-templates', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $templateId = $response->json('data.id');

        $this->assertDatabaseHas('checklist_templates', ['id' => $templateId, 'nama_template' => 'Template Cleanroom Standar Industri']);
        $this->assertDatabaseHas('checklist_template_items', ['checklist_template_id' => $templateId, 'nama_item' => 'Sterilisasi Lantai Epoxy']);

        $updateRoom = $this->actingAs($this->supervisorUser, 'sanctum')
            ->putJson('/api/v1/rooms/' . $this->room->id, [
                'building_id' => $this->building->id,
                'name' => 'Ruang Sterilisasi Updated',
                'code' => 'R-PROD-01',
                'floor' => '1',
                'checklist_template_id' => $templateId,
                'is_active' => true
            ]);

        $updateRoom->assertStatus(200);
        $this->assertDatabaseHas('rooms', ['id' => $this->room->id, 'checklist_template_id' => $templateId]);
    }

    public function test_can_manage_room_assets()
    {
        $payload = [
            'room_id' => $this->room->id,
            'nama_aset' => 'Mesin Autoclave Sterilizer 500L',
            'kode_aset' => 'AST-AUTO-01',
            'kategori' => 'Mesin Steril',
            'kondisi' => 'baik',
            'is_active' => true,
        ];

        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson('/api/v1/room-assets', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $assetId = $response->json('data.id');

        $this->assertDatabaseHas('room_assets', ['id' => $assetId, 'nama_aset' => 'Mesin Autoclave Sterilizer 500L']);
    }

    public function test_can_manage_cleaning_materials()
    {
        $payload = [
            'nama_material' => 'Cairan Desinfektan Isopropil Alkohol 70%',
            'kode_material' => 'CHM-IPA-70',
            'jenis' => 'chemical',
            'satuan' => 'Liter',
            'keterangan' => 'Standar ISO 14644',
            'is_active' => true,
        ];

        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson('/api/v1/cleaning-materials', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $matId = $response->json('data.id');

        $this->assertDatabaseHas('cleaning_materials', ['id' => $matId, 'kode_material' => 'CHM-IPA-70']);
    }

    public function test_can_manage_sla_parameters()
    {
        $payload = [
            'nama_parameter' => 'Tingkat Residu Kimia di Meja Kerja',
            'deskripsi' => 'Pengujian swab residu permukaan',
            'bobot' => 25,
            'tipe_penilaian' => 'scale_1_5',
            'is_active' => true,
        ];

        $response = $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson('/api/v1/sla-parameters', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $slaId = $response->json('data.id');

        $this->assertDatabaseHas('sla_parameters', ['id' => $slaId, 'nama_parameter' => 'Tingkat Residu Kimia di Meja Kerja']);
    }

    public function test_adhoc_task_workflow_with_auto_resume()
    {
        $dispatchRes = $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson('/api/v1/adhoc-tasks', [
                'room_id' => $this->room->id,
                'cs_user_id' => $this->csUser->id,
                'judul' => 'Tumpahan Oli Darurat di Jalur Forklift',
                'deskripsi' => 'Segera bersihkan dengan absorbent pad dan desinfektan lantai.',
                'prioritas' => 'urgent',
            ]);

        $dispatchRes->assertStatus(201);
        $taskId = $dispatchRes->json('data.id');
        $this->assertDatabaseHas('adhoc_tasks', ['id' => $taskId, 'status' => 'pending']);

        $startRes = $this->actingAs($this->csUser, 'sanctum')
            ->postJson("/api/v1/adhoc-tasks/{$taskId}/start");

        $startRes->assertStatus(200);
        $this->assertDatabaseHas('adhoc_tasks', ['id' => $taskId, 'status' => 'in_progress']);

        $photo = UploadedFile::fake()->image('bukti_oli.jpg', 600, 400)->size(500);

        $submitRes = $this->actingAs($this->csUser, 'sanctum')
            ->post("/api/v1/adhoc-tasks/{$taskId}/submit", [
                'foto_bukti' => $photo,
            ]);

        $submitRes->assertStatus(200);
        $this->assertDatabaseHas('adhoc_tasks', [
            'id' => $taskId,
            'status' => 'submitted',
        ]);

        $verifyRes = $this->actingAs($this->supervisorUser, 'sanctum')
            ->postJson("/api/v1/adhoc-tasks/{$taskId}/verify", [
                'status' => 'verified',
                'catatan' => 'Pekerjaan sangat cepat dan rapi. Terverifikasi.',
            ]);

        $verifyRes->assertStatus(200);
        $this->assertDatabaseHas('adhoc_tasks', [
            'id' => $taskId,
            'status' => 'verified',
        ]);
    }

    public function test_deleting_building_cascade_soft_deletes_rooms(): void
    {
        $building = Building::create([
            'kode_gedung' => 'GB-TEST',
            'nama_gedung' => 'Gedung Test Cascade',
            'is_active' => true,
        ]);

        $room1 = Room::create([
            'building_id' => $building->id,
            'kode_ruangan' => 'R-TEST-1',
            'nama_ruangan' => 'Ruangan Test 1',
            'lantai' => 1,
            'is_active' => true,
        ]);

        $room2 = Room::create([
            'building_id' => $building->id,
            'kode_ruangan' => 'R-TEST-2',
            'nama_ruangan' => 'Ruangan Test 2',
            'lantai' => 2,
            'is_active' => true,
        ]);

        // Verify rooms exist in active query
        $this->assertEquals(2, Room::where('building_id', $building->id)->where('is_active', true)->count());

        // Admin deletes the building
        $deleteRes = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson("/api/v1/buildings/{$building->id}");

        $deleteRes->assertStatus(200);

        // Building should be soft deleted
        $this->assertSoftDeleted('buildings', ['id' => $building->id]);

        // Rooms should also be soft deleted and deactivated
        $this->assertSoftDeleted('rooms', ['id' => $room1->id]);
        $this->assertSoftDeleted('rooms', ['id' => $room2->id]);
        $this->assertFalse($room1->fresh()->is_active);
        $this->assertFalse($room2->fresh()->is_active);

        // GET /rooms should not return rooms from deleted buildings
        $getRoomsRes = $this->actingAs($this->adminUser, 'sanctum')
            ->getJson('/api/v1/rooms');

        $getRoomsRes->assertStatus(200);
        $this->assertEmpty($getRoomsRes->json('data.data'));
    }
}
