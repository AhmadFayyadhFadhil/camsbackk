<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Building;
use App\Models\Room;
use App\Enums\RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RoomManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $picUser;
    private Building $building;

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

        // Create admin user for authentication
        $this->adminUser = User::create([
            'username' => 'admin_user',
            'email' => 'admin@cams.com',
            'password' => Hash::make('AdminPass123!'),
            'full_name' => 'Administrator',
            'is_active' => true,
        ]);

        $adminRole = Role::where('name', RoleEnum::ADMIN->value)->first();
        $this->adminUser->userRoles()->create([
            'role_id' => $adminRole->id,
            'assigned_by' => $this->adminUser->id,
        ]);

        // Create PIC user
        $this->picUser = User::create([
            'username' => 'pic_user',
            'email' => 'pic@cams.com',
            'password' => Hash::make('PicPass123!'),
            'full_name' => 'PIC Staff',
            'is_active' => true,
        ]);

        $picRole = Role::where('name', RoleEnum::PIC->value)->first();
        $this->picUser->userRoles()->create([
            'role_id' => $picRole->id,
            'assigned_by' => $this->adminUser->id,
        ]);

        // Create Building
        $this->building = Building::create([
            'kode_gedung' => 'GD-01',
            'nama_gedung' => 'Building Main',
            'alamat' => 'Address 1',
            'is_active' => true,
        ]);

    }

    public function test_can_create_room_with_frontend_format(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/rooms', [
                'building_id' => $this->building->id,
                'name' => 'Pantry Steril',
                'code' => 'PNT-ST',
                'floor' => 2,
                'pic_user_id' => $this->picUser->id,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        
        // Verify database entry
        $this->assertDatabaseHas('rooms', [
            'building_id' => $this->building->id,
            'nama_ruangan' => 'Pantry Steril',
            'kode_ruangan' => 'PNT-ST',
            'lantai' => '2', // Cast to string
            'pic_user_id' => $this->picUser->id,
            'is_active' => true,
        ]);

        // Verify resource JSON mapping
        $response->assertJsonFragment([
            'name' => 'Pantry Steril',
            'code' => 'PNT-ST',
            'floor' => '2',
        ]);
    }

    public function test_can_update_room_with_frontend_format(): void
    {
        // 1. Create Room
        $room = Room::create([
            'building_id' => $this->building->id,
            'nama_ruangan' => 'Old Room Name',
            'kode_ruangan' => 'OLD-RM',
            'lantai' => '1',
            'pic_user_id' => $this->picUser->id,
            'is_active' => true,
        ]);

        // 2. Update Room using PUT
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson('/api/v1/rooms/' . $room->id, [
                'building_id' => $this->building->id,
                'name' => 'New Room Name',
                'code' => 'OLD-RM', // Keep code same to verify unique validation ignore
                'floor' => 3,
                'pic_user_id' => $this->picUser->id,
            ]);

        $response->assertStatus(200);

        // Verify database entry updated
        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'nama_ruangan' => 'New Room Name',
            'kode_ruangan' => 'OLD-RM',
            'lantai' => '3',
        ]);
    }
}
