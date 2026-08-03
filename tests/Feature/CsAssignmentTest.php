<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Role;
use App\Models\Building;
use App\Models\Shift;
use App\Models\Room;
use App\Models\Schedule;
use App\Models\Task;
use App\Models\CsAssignment;
use App\Enums\RoleEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\FrequencyEnum;
use App\Services\TaskGeneratorService;
use App\Helpers\ShiftValidatorHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;
use Tests\TestCase;

class CsAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private User $csUser1;
    private User $csUser2;
    private Building $buildingA;
    private Shift $shift1;
    private Shift $shift2;
    private Room $room1;
    private Schedule $schedule1;

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

        // Create Admin
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

        // Create CS Users
        $this->csUser1 = User::create([
            'username' => 'cs_user1',
            'email' => 'cs1@cams.com',
            'password' => Hash::make('CsPass123!'),
            'full_name' => 'Budi CS',
            'is_active' => true,
        ]);
        $csRole = Role::where('name', RoleEnum::CS->value)->first();
        $this->csUser1->userRoles()->create(['role_id' => $csRole->id, 'assigned_by' => $this->adminUser->id]);

        $this->csUser2 = User::create([
            'username' => 'cs_user2',
            'email' => 'cs2@cams.com',
            'password' => Hash::make('CsPass123!'),
            'full_name' => 'Ani CS',
            'is_active' => true,
        ]);
        $this->csUser2->userRoles()->create(['role_id' => $csRole->id, 'assigned_by' => $this->adminUser->id]);

        // Create Building
        $this->buildingA = Building::create([
            'kode_gedung' => 'BLDG-A',
            'nama_gedung' => 'Gedung A',
            'alamat' => 'Gedung A Office',
            'is_active' => true,
        ]);

        // Create Shifts
        $this->shift1 = Shift::create([
            'kode_shift' => 'S1',
            'nama_shift' => 'Shift 1',
            'jam_mulai' => '06:00:00',
            'jam_selesai' => '14:00:00',
            'is_overnight' => false,
            'is_active' => true,
        ]);

        $this->shift2 = Shift::create([
            'kode_shift' => 'S2',
            'nama_shift' => 'Shift 2',
            'jam_mulai' => '14:00:00',
            'jam_selesai' => '22:00:00',
            'is_overnight' => false,
            'is_active' => true,
        ]);

        $this->buildingA->shifts()->attach([$this->shift1->id, $this->shift2->id]);

        // Create Room
        $this->room1 = Room::create([
            'building_id' => $this->buildingA->id,
            'kode_ruangan' => 'ROOM-101',
            'nama_ruangan' => 'Ruang Meeting 101',
            'lantai' => 1,
            'qr_code_token' => 'token123',
            'is_active' => true,
        ]);

        // Create Schedule
        $this->schedule1 = Schedule::create([
            'room_id' => $this->room1->id,
            'checklist_item_id' => \App\Models\ChecklistItem::create([
                'nama_item' => 'Sapu Lantai',
                'kategori' => 'Kebersihan',
                'deskripsi' => 'Sapu seluruh permukaan lantai',
                'is_active' => true
            ])->id,
            'shift_id' => $this->shift1->id,
            'frekuensi' => 'harian',
            'is_active' => true,
        ]);
    }

    public function test_can_assign_cs_to_building_without_shift(): void
    {
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/cs-assignments', [
                'cs_user_id' => $this->csUser1->id,
                'building_id' => $this->buildingA->id,
                'shift_id' => null, // No shift assigned
                'tanggal_mulai' => '2026-06-24',
                'tanggal_selesai' => '2026-06-24',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('cs_assignments', [
            'cs_user_id' => $this->csUser1->id,
            'building_id' => $this->buildingA->id,
            'shift_id' => null,
            'tanggal_mulai' => '2026-06-24',
            'tanggal_selesai' => '2026-06-24',
        ]);
    }

    public function test_cannot_assign_overlapping_date_ranges_if_shift_is_null(): void
    {
        // Create first assignment without shift
        CsAssignment::create([
            'cs_user_id' => $this->csUser1->id,
            'building_id' => $this->buildingA->id,
            'shift_id' => null,
            'tanggal_mulai' => '2026-06-24',
            'tanggal_selesai' => '2026-06-24',
        ]);

        // Try to create second assignment for same date (with or without shift)
        $response = $this->actingAs($this->adminUser, 'sanctum')
            ->postJson('/api/v1/cs-assignments', [
                'cs_user_id' => $this->csUser1->id,
                'building_id' => $this->buildingA->id,
                'shift_id' => $this->shift1->id,
                'tanggal_mulai' => '2026-06-24',
                'tanggal_selesai' => '2026-06-24',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('overlap');
    }

    public function test_task_generator_falls_back_to_general_building_assignment(): void
    {
        // CS assigned to building A with NO specific shift
        CsAssignment::create([
            'cs_user_id' => $this->csUser1->id,
            'building_id' => $this->buildingA->id,
            'shift_id' => null,
            'tanggal_mulai' => '2026-06-24',
            'tanggal_selesai' => '2026-06-24',
        ]);

        $service = new TaskGeneratorService();
        $results = $service->generateForDate(Carbon::parse('2026-06-24'));

        $this->assertEquals(1, $results['generated']);
        $this->assertDatabaseHas('tasks', [
            'schedule_id' => $this->schedule1->id,
            'cs_user_id' => $this->csUser1->id, // Budi CS assigned because of building fallback
            'tanggal_task' => '2026-06-24',
        ]);
    }

    public function test_scan_qr_code_assigns_pending_task_to_scanner(): void
    {
        // 1. Create a task assigned to CS 1 (Budi) or Null
        $task = Task::create([
            'schedule_id' => $this->schedule1->id,
            'room_id' => $this->room1->id,
            'cs_user_id' => $this->csUser1->id, // pre-assigned to Budi
            'shift_id' => $this->shift1->id,
            'tanggal_task' => '2026-06-24',
            'status' => TaskStatusEnum::PENDING,
            'due_datetime' => '2026-06-24 14:00:00',
        ]);

        // Mock current shift to return shift1
        Carbon::setTestNow(Carbon::parse('2026-06-24 09:30:00', 'Asia/Jakarta'));

        // Assign CS 2 (Ani) to the building today
        CsAssignment::create([
            'cs_user_id' => $this->csUser2->id,
            'building_id' => $this->buildingA->id,
            'shift_id' => null,
            'tanggal_mulai' => '2026-06-24',
            'tanggal_selesai' => '2026-06-24',
        ]);

        // CS 2 (Ani) scans the QR Code
        $response = $this->actingAs($this->csUser2, 'sanctum')
            ->postJson('/api/v1/submissions/scan', [
                'room_id' => $this->room1->id,
                'qr_code_token' => 'token123',
            ]);

        $response->assertStatus(200);

        // Verify task is now IN_PROGRESS and assigned to CS 2 (Ani)
        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'cs_user_id' => $this->csUser2->id, // Swapped to Ani
            'status' => TaskStatusEnum::IN_PROGRESS,
        ]);
    }
}
