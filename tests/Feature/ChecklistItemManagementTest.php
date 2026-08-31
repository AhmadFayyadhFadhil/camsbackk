<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\ChecklistItem;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistResult;
use App\Models\Verification;
use App\Enums\RoleEnum;
use App\Enums\FrequencyEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\SubmissionStatusEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChecklistItemManagementTest extends TestCase
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

        $adminRole = Role::where('name', RoleEnum::ADMIN->value)->first();
        $this->adminUser->userRoles()->create([
            'role_id' => $adminRole->id,
            'assigned_by' => $this->adminUser->id,
        ]);
    }

    public function test_can_create_checklist_item(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/checklist-items', [
                'nama_item' => 'Sapu halaman depan',
                'kategori' => 'Halaman',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('checklist_items', [
            'nama_item' => 'Sapu halaman depan',
            'kategori' => 'Halaman',
            'is_active' => true,
        ]);
    }

    public function test_can_update_checklist_item(): void
    {
        $item = ChecklistItem::create([
            'nama_item' => 'Sapu halaman depan',
            'kategori' => 'Halaman',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->putJson('/api/v1/checklist-items/' . $item->id, [
                'nama_item' => 'Sapu & pel halaman depan',
                'kategori' => 'Halaman Baru',
                'is_active' => false,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('checklist_items', [
            'id' => $item->id,
            'nama_item' => 'Sapu & pel halaman depan',
            'kategori' => 'Halaman Baru',
        ]);
    }

    public function test_can_hard_delete_checklist_item_and_relations(): void
    {
        // 1. Create a checklist item
        $item = ChecklistItem::create([
            'nama_item' => 'Sapu halaman depan',
            'kategori' => 'Halaman',
            'is_active' => true,
            'created_by' => $this->adminUser->id,
        ]);

        // Create other dependent models (mocking structure)
        // Building
        $building = \App\Models\Building::create([
            'nama_gedung' => 'Gedung A',
            'kode_gedung' => 'GDA',
            'alamat' => 'Alamat A',
            'is_active' => true,
        ]);

        // Room
        $room = \App\Models\Room::create([
            'building_id' => $building->id,
            'nama_ruangan' => 'Lobby',
            'kode_ruangan' => 'LBY',
            'is_active' => true,
        ]);

        // Shift
        $shift = \App\Models\Shift::create([
            'nama_shift' => 'Pagi',
            'kode_shift' => 'PGI',
            'jam_mulai' => '08:00:00',
            'jam_selesai' => '16:00:00',
        ]);

        // 2. Create Schedule
        $schedule = Schedule::create([
            'room_id' => $room->id,
            'checklist_item_id' => $item->id,
            'shift_id' => $shift->id,
            'frekuensi' => FrequencyEnum::HARIAN,
            'is_active' => true,
        ]);

        // CS User
        $csUser = User::create([
            'username' => 'cs_user',
            'email' => 'cs@cams.com',
            'password' => Hash::make('CsPass123!'),
            'full_name' => 'Cleaning Service',
            'is_active' => true,
        ]);

        // 3. Create Task
        $task = Task::create([
            'schedule_id' => $schedule->id,
            'room_id' => $room->id,
            'cs_user_id' => $csUser->id,
            'shift_id' => $shift->id,
            'tanggal_task' => today(),
            'status' => TaskStatusEnum::WAITING_VERIFICATION,
            'due_datetime' => now()->addHours(8),
        ]);

        // 4. Create Submission
        $submission = ChecklistSubmission::create([
            'task_id' => $task->id,
            'cs_user_id' => $csUser->id,
            'submitted_at' => now(),
            'status' => SubmissionStatusEnum::SUBMITTED,
            'scan_token_used' => (string) Str::uuid(),
        ]);

        // 5. Create Result
        $result = ChecklistResult::create([
            'submission_id' => $submission->id,
            'checklist_item_id' => $item->id,
            'is_done' => true,
            'catatan' => 'Bersih',
        ]);

        // Supervisor User
        $spvUser = User::create([
            'username' => 'spv_user',
            'email' => 'spv@cams.com',
            'password' => Hash::make('SpvPass123!'),
            'full_name' => 'Supervisor',
            'is_active' => true,
        ]);

        // 6. Create Verification
        $verification = Verification::create([
            'submission_id' => $submission->id,
            'verified_by' => $spvUser->id,
            'role_verifier' => 'supervisor',
            'status' => 'approved',
            'catatan_perbaikan' => 'Sesuai',
            'verified_at' => now(),
        ]);

        // Verify everything exists before deletion
        $this->assertDatabaseHas('checklist_items', ['id' => $item->id]);
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('checklist_submissions', ['id' => $submission->id]);
        $this->assertDatabaseHas('checklist_results', ['id' => $result->id]);
        $this->assertDatabaseHas('verifications', ['id' => $verification->id]);

        // 7. Perform deletion
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->deleteJson('/api/v1/checklist-items/' . $item->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        // 8. Verify soft delete and historical data preservation for audit compliance
        $this->assertSoftDeleted('checklist_items', ['id' => $item->id]);
        $this->assertDatabaseHas('schedules', ['id' => $schedule->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('checklist_submissions', ['id' => $submission->id]);
        $this->assertDatabaseHas('checklist_results', ['id' => $result->id]);
        $this->assertDatabaseHas('verifications', ['id' => $verification->id]);
    }
}
