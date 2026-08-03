<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Finding;
use App\Models\Room;
use App\Models\Building;
use App\Enums\RoleEnum;
use App\Enums\PriorityEnum;
use App\Enums\FindingStatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FindingStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles
        foreach (RoleEnum::cases() as $role) {
            Role::create([
                'name' => $role->value,
                'description' => 'Role ' . $role->value,
            ]);
        }
    }

    public function test_cs_user_can_update_status_to_resolved()
    {
        // 1. Create building & room
        $building = Building::create([
            'nama_gedung' => 'Test Building',
            'kode_gedung' => 'TB',
            'alamat' => 'Test Alamat',
            'is_active' => true,
        ]);

        $room = Room::create([
            'building_id' => $building->id,
            'kode_ruangan' => 'TR-01',
            'nama_ruangan' => 'Test Room',
            'lantai' => '1',
            'is_active' => true,
            'qr_code_token' => 'test-token',
        ]);

        // 2. Create CS user
        $csUser = User::create([
            'username' => 'test_cs',
            'email' => 'test_cs@cams.com',
            'full_name' => 'Test CS User',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);

        $csRole = Role::where('name', RoleEnum::CS->value)->first();
        if ($csRole) {
            \App\Models\UserRole::create(['user_id' => $csUser->id, 'role_id' => $csRole->id]);
        }

        // 3. Create finding
        $finding = Finding::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'room_id' => $room->id,
            'reported_by' => $csUser->id,
            'deskripsi' => 'Testing status update',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::OPEN,
            'foto_finding' => 'mock_binary_data',
            'foto_finding_mime' => 'image/jpeg'
        ]);

        // 4. Hit endpoint as CS user
        $response = $this->actingAs($csUser, 'sanctum')
            ->patchJson("/api/v1/findings/{$finding->id}/status", [
                'status' => 'resolved'
            ]);

        // Dump response if it fails
        if ($response->getStatusCode() !== 200) {
            dump($response->getContent());
        }

        $response->assertStatus(200);
        $this->assertEquals(FindingStatusEnum::RESOLVED->value, $finding->fresh()->status->value);
    }

    public function test_status_unresolved_filter_excludes_resolved_findings()
    {
        // 1. Create building & room
        $building = Building::create([
            'nama_gedung' => 'Test Building',
            'kode_gedung' => 'TB',
            'alamat' => 'Test Alamat',
            'is_active' => true,
        ]);

        $room = Room::create([
            'building_id' => $building->id,
            'kode_ruangan' => 'TR-01',
            'nama_ruangan' => 'Test Room',
            'lantai' => '1',
            'is_active' => true,
            'qr_code_token' => 'test-token',
        ]);

        // 2. Create CS user
        $csUser = User::create([
            'username' => 'test_cs_2',
            'email' => 'test_cs2@cams.com',
            'full_name' => 'Test CS User 2',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);

        $csRole = Role::where('name', RoleEnum::CS->value)->first();
        if ($csRole) {
            \App\Models\UserRole::create(['user_id' => $csUser->id, 'role_id' => $csRole->id]);
        }

        // 3. Create findings with different statuses
        Finding::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'room_id' => $room->id,
            'reported_by' => $csUser->id,
            'deskripsi' => 'Finding Open',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::OPEN,
            'foto_finding' => 'mock_binary_data',
            'foto_finding_mime' => 'image/jpeg'
        ]);

        Finding::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'room_id' => $room->id,
            'reported_by' => $csUser->id,
            'deskripsi' => 'Finding In Progress',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::IN_PROGRESS,
            'foto_finding' => 'mock_binary_data',
            'foto_finding_mime' => 'image/jpeg'
        ]);

        Finding::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'room_id' => $room->id,
            'reported_by' => $csUser->id,
            'deskripsi' => 'Finding Resolved',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::RESOLVED,
            'foto_finding' => 'mock_binary_data',
            'foto_finding_mime' => 'image/jpeg',
            'resolved_at' => now()
        ]);

        // 4. Hit index endpoint with status=unresolved
        $responseUnresolved = $this->actingAs($csUser, 'sanctum')
            ->getJson("/api/v1/findings?status=unresolved");

        $responseUnresolved->assertStatus(200);
        $dataUnresolved = $responseUnresolved->json('data.data') ?? $responseUnresolved->json('data');
        $this->assertCount(2, $dataUnresolved);
        
        $descriptions = collect($dataUnresolved)->pluck('deskripsi')->toArray();
        $this->assertContains('Finding Open', $descriptions);
        $this->assertContains('Finding In Progress', $descriptions);
        $this->assertNotContains('Finding Resolved', $descriptions);

        // 5. Hit index endpoint with status=resolved
        $responseResolved = $this->actingAs($csUser, 'sanctum')
            ->getJson("/api/v1/findings?status=resolved");

        $responseResolved->assertStatus(200);
        $dataResolved = $responseResolved->json('data.data') ?? $responseResolved->json('data');
        $this->assertCount(1, $dataResolved);
        $this->assertEquals('Finding Resolved', $dataResolved[0]['deskripsi']);
    }

    public function test_admin_can_delete_any_finding()
    {
        // Setup
        $building = Building::create(['nama_gedung' => 'B1', 'kode_gedung' => 'B1', 'alamat' => 'A1', 'is_active' => true]);
        $room = Room::create(['building_id' => $building->id, 'kode_ruangan' => 'R1', 'nama_ruangan' => 'R1', 'lantai' => '1', 'is_active' => true, 'qr_code_token' => 't1']);
        
        $admin = User::create(['username' => 'test_admin', 'email' => 'admin@test.com', 'full_name' => 'Admin User', 'password' => bcrypt('Password123!'), 'is_active' => true]);
        $adminRole = Role::where('name', RoleEnum::ADMIN->value)->first();
        \App\Models\UserRole::create(['user_id' => $admin->id, 'role_id' => $adminRole->id]);

        $finding = Finding::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'room_id' => $room->id,
            'reported_by' => $admin->id,
            'deskripsi' => 'To delete',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::OPEN,
            'foto_finding' => 'mock_binary_data',
            'foto_finding_mime' => 'image/jpeg'
        ]);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/findings/{$finding->id}");
        $response->assertStatus(200);
        $this->assertNull(Finding::find($finding->id));
    }

    public function test_room_pic_can_delete_finding()
    {
        // Setup
        $building = Building::create(['nama_gedung' => 'B1', 'kode_gedung' => 'B1', 'alamat' => 'A1', 'is_active' => true]);
        
        $picUser = User::create(['username' => 'test_pic', 'email' => 'pic@test.com', 'full_name' => 'PIC User', 'password' => bcrypt('Password123!'), 'is_active' => true]);
        $picRole = Role::where('name', RoleEnum::PIC->value)->first();
        \App\Models\UserRole::create(['user_id' => $picUser->id, 'role_id' => $picRole->id]);

        $room = Room::create([
            'building_id' => $building->id,
            'kode_ruangan' => 'R1',
            'nama_ruangan' => 'R1',
            'lantai' => '1',
            'is_active' => true,
            'qr_code_token' => 't1',
            'pic_user_id' => $picUser->id // Set PIC of the room
        ]);

        $finding = Finding::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'room_id' => $room->id,
            'reported_by' => $picUser->id,
            'deskripsi' => 'To delete',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::OPEN,
            'foto_finding' => 'mock_binary_data',
            'foto_finding_mime' => 'image/jpeg'
        ]);

        $response = $this->actingAs($picUser, 'sanctum')->deleteJson("/api/v1/findings/{$finding->id}");
        $response->assertStatus(200);
        $this->assertNull(Finding::find($finding->id));
    }

    public function test_other_pic_can_delete_finding()
    {
        // Setup
        $building = Building::create(['nama_gedung' => 'B1', 'kode_gedung' => 'B1', 'alamat' => 'A1', 'is_active' => true]);
        
        $pic1 = User::create(['username' => 'test_pic1', 'email' => 'pic1@test.com', 'full_name' => 'PIC User 1', 'password' => bcrypt('Password123!'), 'is_active' => true]);
        $pic2 = User::create(['username' => 'test_pic2', 'email' => 'pic2@test.com', 'full_name' => 'PIC User 2', 'password' => bcrypt('Password123!'), 'is_active' => true]);
        $picRole = Role::where('name', RoleEnum::PIC->value)->first();
        \App\Models\UserRole::create(['user_id' => $pic1->id, 'role_id' => $picRole->id]);
        \App\Models\UserRole::create(['user_id' => $pic2->id, 'role_id' => $picRole->id]);

        $room = Room::create([
            'building_id' => $building->id,
            'kode_ruangan' => 'R1',
            'nama_ruangan' => 'R1',
            'lantai' => '1',
            'is_active' => true,
            'qr_code_token' => 't1',
            'pic_user_id' => $pic1->id // PIC 1 owns the room
        ]);

        $finding = Finding::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'room_id' => $room->id,
            'reported_by' => $pic1->id,
            'deskripsi' => 'To delete',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::OPEN,
            'foto_finding' => 'mock_binary_data',
            'foto_finding_mime' => 'image/jpeg'
        ]);

        // PIC 2 tries to delete, should succeed
        $response = $this->actingAs($pic2, 'sanctum')->deleteJson("/api/v1/findings/{$finding->id}");
        $response->assertStatus(200);
        $this->assertNull(Finding::find($finding->id));
    }

    public function test_cs_user_cannot_delete_finding()
    {
        // Setup
        $building = Building::create(['nama_gedung' => 'B1', 'kode_gedung' => 'B1', 'alamat' => 'A1', 'is_active' => true]);
        $room = Room::create(['building_id' => $building->id, 'kode_ruangan' => 'R1', 'nama_ruangan' => 'R1', 'lantai' => '1', 'is_active' => true, 'qr_code_token' => 't1']);
        
        $csUser = User::create(['username' => 'test_cs_del', 'email' => 'cs_del@test.com', 'full_name' => 'CS User', 'password' => bcrypt('Password123!'), 'is_active' => true]);
        $csRole = Role::where('name', RoleEnum::CS->value)->first();
        \App\Models\UserRole::create(['user_id' => $csUser->id, 'role_id' => $csRole->id]);

        $finding = Finding::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'room_id' => $room->id,
            'reported_by' => $csUser->id,
            'deskripsi' => 'To delete',
            'prioritas' => PriorityEnum::MEDIUM,
            'status' => FindingStatusEnum::OPEN,
            'foto_finding' => 'mock_binary_data',
            'foto_finding_mime' => 'image/jpeg'
        ]);

        // CS tries to delete, should fail at route middleware level (CS role not in delete route middleware)
        $response = $this->actingAs($csUser, 'sanctum')->deleteJson("/api/v1/findings/{$finding->id}");
        $response->assertStatus(403);
        $this->assertNotNull(Finding::find($finding->id));
    }
}
