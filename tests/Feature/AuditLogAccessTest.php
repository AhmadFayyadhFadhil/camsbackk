<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Enums\RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuditLogAccessTest extends TestCase
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

    private function createUserWithRole(string $username, RoleEnum $roleEnum): User
    {
        $user = User::create([
            'username' => $username,
            'email' => $username . '@cams.com',
            'full_name' => ucfirst($username) . ' User',
            'password' => bcrypt('Password123!'),
            'is_active' => true,
        ]);

        $role = Role::where('name', $roleEnum->value)->first();
        if ($role) {
            \App\Models\UserRole::create(['user_id' => $user->id, 'role_id' => $role->id]);
        }

        return $user;
    }

    public function test_admin_can_access_audit_logs()
    {
        $admin = $this->createUserWithRole('admin', RoleEnum::ADMIN);
        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/audit-logs');
        $response->assertStatus(200);
    }

    public function test_supervisor_can_access_audit_logs()
    {
        $supervisor = $this->createUserWithRole('supervisor', RoleEnum::SUPERVISOR);
        $response = $this->actingAs($supervisor, 'sanctum')->getJson('/api/v1/audit-logs');
        $response->assertStatus(200);
    }

    public function test_pic_can_access_audit_logs()
    {
        $pic = $this->createUserWithRole('pic', RoleEnum::PIC);
        $response = $this->actingAs($pic, 'sanctum')->getJson('/api/v1/audit-logs');
        $response->assertStatus(200);
    }

    public function test_cs_can_access_audit_logs()
    {
        $cs = $this->createUserWithRole('cs', RoleEnum::CS);
        $response = $this->actingAs($cs, 'sanctum')->getJson('/api/v1/audit-logs');
        $response->assertStatus(200);
    }

    public function test_cs_and_pic_only_see_their_own_logs_while_admin_sees_all()
    {
        $admin = $this->createUserWithRole('admin', RoleEnum::ADMIN);
        $cs = $this->createUserWithRole('cs', RoleEnum::CS);
        $pic = $this->createUserWithRole('pic', RoleEnum::PIC);

        // Create log for CS
        \App\Models\AuditLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $cs->id,
            'action' => 'CS_ACTION',
            'entity_type' => 'tasks',
            'entity_id' => (string) \Illuminate\Support\Str::uuid(),
            'old_data' => null,
            'new_data' => ['test' => 'cs'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit'
        ]);

        // Create log for PIC
        \App\Models\AuditLog::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $pic->id,
            'action' => 'PIC_ACTION',
            'entity_type' => 'rooms',
            'entity_id' => (string) \Illuminate\Support\Str::uuid(),
            'old_data' => null,
            'new_data' => ['test' => 'pic'],
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit'
        ]);

        // 1. CS user accesses logs
        $responseCs = $this->actingAs($cs, 'sanctum')->getJson('/api/v1/audit-logs');
        $responseCs->assertStatus(200);
        $dataCs = $responseCs->json('data.data') ?? $responseCs->json('data');
        $this->assertCount(1, $dataCs);
        $this->assertEquals('CS_ACTION', $dataCs[0]['action']);

        // 2. PIC user accesses logs
        $responsePic = $this->actingAs($pic, 'sanctum')->getJson('/api/v1/audit-logs');
        $responsePic->assertStatus(200);
        $dataPic = $responsePic->json('data.data') ?? $responsePic->json('data');
        $this->assertCount(1, $dataPic);
        $this->assertEquals('PIC_ACTION', $dataPic[0]['action']);

        // 3. Admin user accesses logs
        $responseAdmin = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/audit-logs');
        $responseAdmin->assertStatus(200);
        $dataAdmin = $responseAdmin->json('data.data') ?? $responseAdmin->json('data');
        $this->assertCount(2, $dataAdmin);
    }
}
