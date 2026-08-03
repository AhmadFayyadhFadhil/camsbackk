<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Enums\RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;

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

        // Assign admin role
        $adminRole = Role::where('name', RoleEnum::ADMIN->value)->first();
        $this->adminUser->userRoles()->create([
            'role_id' => $adminRole->id,
            'assigned_by' => $this->adminUser->id,
        ]);
    }

    public function test_can_create_user_with_frontend_format(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/users', [
                'name' => 'Test Cleaning Service',
                'email' => 'cs.test@cams.com',
                'password' => 'CsPass123!',
                'roles' => ['cleaning_service'],
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        
        // Verify database entry
        $this->assertDatabaseHas('users', [
            'email' => 'cs.test@cams.com',
            'username' => 'cstest', // Auto-generated from email part
            'full_name' => 'Test Cleaning Service',
            'is_active' => true,
        ]);

        $createdUser = User::where('email', 'cs.test@cams.com')->first();
        $this->assertTrue($createdUser->hasRole(RoleEnum::CS));
    }

    public function test_can_update_user_with_frontend_format(): void
    {
        // 1. Create a CS user first
        $csUser = User::create([
            'username' => 'cstest',
            'email' => 'cs.test@cams.com',
            'password' => Hash::make('CsPass123!'),
            'full_name' => 'Old Name',
            'is_active' => true,
        ]);
        $csRole = Role::where('name', RoleEnum::CS->value)->first();
        $csUser->userRoles()->create([
            'role_id' => $csRole->id,
            'assigned_by' => $this->adminUser->id,
        ]);

        // 2. Update user using PUT
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson('/api/v1/users/' . $csUser->id, [
                'name' => 'New Cleaning Service Name',
                'email' => 'cs.test@cams.com', // same email should be allowed
                'roles' => ['cleaning_service'],
                'is_active' => false,
            ]);

        $response->assertStatus(200);
        
        // Verify database updated
        $this->assertDatabaseHas('users', [
            'id' => $csUser->id,
            'full_name' => 'New Cleaning Service Name',
            'email' => 'cs.test@cams.com',
            'is_active' => false,
        ]);
    }
}
