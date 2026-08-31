<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Notification;
use App\Enums\RoleEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;

class NotificationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (RoleEnum::cases() as $role) {
            Role::firstOrCreate(['name' => $role->value], ['description' => 'Role ' . $role->value]);
        }

        $this->user = User::create([
            'username' => 'notif_user',
            'email' => 'notif_user@cams.com',
            'password' => bcrypt('Password123!'),
            'full_name' => 'Notification Test User',
            'is_active' => true,
        ]);
        $spvRole = Role::where('name', RoleEnum::SUPERVISOR->value)->first();
        $this->user->userRoles()->create(['role_id' => $spvRole->id, 'assigned_by' => $this->user->id]);
    }

    public function test_can_list_notifications_with_counts(): void
    {
        Notification::create([
            'user_id' => $this->user->id,
            'type' => 'TASK_ASSIGNED',
            'title' => 'Notif 1',
            'message' => 'Message 1',
            'is_read' => false,
        ]);

        Notification::create([
            'user_id' => $this->user->id,
            'type' => 'TASK_ASSIGNED',
            'title' => 'Notif 2',
            'message' => 'Message 2',
            'is_read' => true,
            'read_at' => now(),
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/notifications');

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total_count', 2)
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('meta.read_count', 1);
    }

    public function test_can_mark_all_notifications_as_read(): void
    {
        $n1 = Notification::create([
            'user_id' => $this->user->id,
            'type' => 'TASK_ASSIGNED',
            'title' => 'Notif 1',
            'message' => 'Message 1',
            'is_read' => false,
        ]);

        $n2 = Notification::create([
            'user_id' => $this->user->id,
            'type' => 'TASK_ASSIGNED',
            'title' => 'Notif 2',
            'message' => 'Message 2',
            'is_read' => false,
        ]);

        $res = $this->actingAs($this->user, 'sanctum')->patchJson('/api/v1/notifications/mark-all-read');

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue($n1->fresh()->is_read);
        $this->assertTrue($n2->fresh()->is_read);
    }

    public function test_can_delete_single_and_all_notifications(): void
    {
        $n1 = Notification::create([
            'user_id' => $this->user->id,
            'type' => 'TASK_ASSIGNED',
            'title' => 'Notif 1',
            'message' => 'Message 1',
            'is_read' => false,
        ]);

        $n2 = Notification::create([
            'user_id' => $this->user->id,
            'type' => 'TASK_ASSIGNED',
            'title' => 'Notif 2',
            'message' => 'Message 2',
            'is_read' => true,
        ]);

        // Delete single
        $delSingle = $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/notifications/{$n1->id}");
        $delSingle->assertStatus(200);
        $this->assertDatabaseMissing('notifications', ['id' => $n1->id]);
        $this->assertDatabaseHas('notifications', ['id' => $n2->id]);

        // Delete all
        $delAll = $this->actingAs($this->user, 'sanctum')->deleteJson('/api/v1/notifications/delete-all');
        $delAll->assertStatus(200);
        $this->assertDatabaseCount('notifications', 0);
    }
}
