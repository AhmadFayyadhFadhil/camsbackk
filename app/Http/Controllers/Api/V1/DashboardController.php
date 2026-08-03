<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Room;
use App\Models\Task;
use App\Models\ChecklistSubmission;
use App\Models\Finding;
use App\Models\Shift;
use App\Enums\TaskStatusEnum;
use App\Enums\SubmissionStatusEnum;
use App\Enums\FindingStatusEnum;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ApiResponse;

    /**
     * Dashboard untuk Supervisor.
     * Agregasi data seluruh gedung dan ruangan dengan caching Redis 60 detik.
     */
    public function supervisor(Request $request)
    {
        $dateFrom = $request->get('date_from', today()->toDateString());
        $dateTo = $request->get('date_to', today()->toDateString());
        $refresh = $request->has('refresh');

        $cacheKey = "dashboard:supervisor:{$dateFrom}:{$dateTo}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 60, function () use ($dateFrom, $dateTo) {
            // 1. Total Gedung & Ruangan Aktif
            $totalBuildings = Building::where('is_active', true)->count();
            $totalRooms = Room::where('is_active', true)->count();

            // 2. Kepatuhan (Compliance Rate) dalam rentang waktu
            $taskQuery = Task::whereBetween('tanggal_task', [$dateFrom, $dateTo]);
            $totalTasks = (clone $taskQuery)->count();
            $completedTasks = (clone $taskQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
            $complianceRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0.0;

            // 3. Breakdown Kepatuhan per Gedung
            $buildingsData = Building::where('is_active', true)->get()->map(function ($building) use ($dateFrom, $dateTo) {
                $roomIds = $building->rooms()->pluck('id');
                $bTasksQuery = Task::whereIn('room_id', $roomIds)->whereBetween('tanggal_task', [$dateFrom, $dateTo]);
                $bTotal = (clone $bTasksQuery)->count();
                $bCompleted = (clone $bTasksQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
                $bCompliance = $bTotal > 0 ? round(($bCompleted / $bTotal) * 100, 2) : 0.0;

                return [
                    'building_id' => $building->id,
                    'building_name' => $building->nama_gedung,
                    'building_code' => $building->kode_gedung,
                    'total_rooms' => $building->rooms()->count(),
                    'total_tasks' => $bTotal,
                    'completed_tasks' => $bCompleted,
                    'compliance_rate' => $bCompliance,
                ];
            });

            // 4. Antrean Verifikasi Tertunda (Pending Submissions)
            $pendingVerificationsCount = ChecklistSubmission::where('status', SubmissionStatusEnum::SUBMITTED)->count();

            // 5. Temuan Masalah Aktif (status open & in_progress)
            $activeFindingsCount = Finding::whereIn('status', [FindingStatusEnum::OPEN, FindingStatusEnum::IN_PROGRESS])->count();

            // 6. Tugas Overdue Terbaru (limit 5)
            $recentOverdueTasks = Task::where('status', TaskStatusEnum::OVERDUE)
                ->whereBetween('tanggal_task', [$dateFrom, $dateTo])
                ->with(['room', 'cs'])
                ->orderBy('due_datetime', 'desc')
                ->take(5)
                ->get()
                ->map(fn($t) => [
                    'task_id' => $t->id,
                    'room_name' => $t->room?->nama_ruangan,
                    'cs_name' => $t->cs?->full_name ?? 'Belum Ditugaskan',
                    'due_datetime' => $t->due_datetime?->toDateTimeString(),
                    'task_date' => $t->tanggal_task->toDateString(),
                ]);

            return [
                'total_buildings' => $totalBuildings,
                'total_rooms' => $totalRooms,
                'compliance_rate' => $complianceRate,
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'breakdown_per_building' => $buildingsData,
                'pending_verifications' => $pendingVerificationsCount,
                'active_findings' => $activeFindingsCount,
                'overdue_tasks' => $recentOverdueTasks,
            ];
        });

        return $this->success($data, 'Data dashboard supervisor berhasil diambil.');
    }

    /**
     * Dashboard untuk PIC.
     * Agregasi data dibatasi khusus area kelolaan PIC yang login.
     */
    public function pic(Request $request)
    {
        $user = $request->user();
        $dateFrom = $request->get('date_from', today()->toDateString());
        $dateTo = $request->get('date_to', today()->toDateString());
        $refresh = $request->has('refresh');

        $cacheKey = "dashboard:pic:{$user->id}:{$dateFrom}:{$dateTo}";

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $data = Cache::remember($cacheKey, 60, function () use ($user, $dateFrom, $dateTo) {
            // Ambil ID ruangan di bawah kelolaan PIC
            $roomIds = Room::where('pic_user_id', $user->id)
                ->pluck('id')
                ->toArray();

            if (empty($roomIds)) {
                return [
                    'total_buildings' => 0,
                    'total_rooms' => 0,
                    'compliance_rate' => 0.0,
                    'total_tasks' => 0,
                    'completed_tasks' => 0,
                    'breakdown_per_building' => [],
                    'pending_verifications' => 0,
                    'active_findings' => 0,
                    'overdue_tasks' => [],
                ];
            }

            // 1. Total Ruangan Kelolaan & Gedung terkait
            $totalRooms = count($roomIds);
            $totalBuildings = Room::whereIn('id', $roomIds)->distinct('building_id')->count('building_id');

            // 2. Kepatuhan (Compliance Rate) dalam rentang waktu
            $taskQuery = Task::whereIn('room_id', $roomIds)->whereBetween('tanggal_task', [$dateFrom, $dateTo]);
            $totalTasks = (clone $taskQuery)->count();
            $completedTasks = (clone $taskQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
            $complianceRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 2) : 0.0;

            // 3. Breakdown per Gedung (terbatas ruangan kelolaan)
            $buildingIds = Room::whereIn('id', $roomIds)->distinct('building_id')->pluck('building_id')->toArray();
            $buildingsData = Building::whereIn('id', $buildingIds)->get()->map(function ($building) use ($roomIds, $dateFrom, $dateTo) {
                // Hanya hitung ruangan kelolaan PIC yang berada di gedung ini
                $bRoomIds = Room::whereIn('id', $roomIds)->where('building_id', $building->id)->pluck('id');
                $bTasksQuery = Task::whereIn('room_id', $bRoomIds)->whereBetween('tanggal_task', [$dateFrom, $dateTo]);
                $bTotal = (clone $bTasksQuery)->count();
                $bCompleted = (clone $bTasksQuery)->where('status', TaskStatusEnum::COMPLETED)->count();
                $bCompliance = $bTotal > 0 ? round(($bCompleted / $bTotal) * 100, 2) : 0.0;

                return [
                    'building_id' => $building->id,
                    'building_name' => $building->nama_gedung,
                    'building_code' => $building->kode_gedung,
                    'total_rooms' => count($bRoomIds),
                    'total_tasks' => $bTotal,
                    'completed_tasks' => $bCompleted,
                    'compliance_rate' => $bCompliance,
                ];
            });

            // 4. Antrean Verifikasi Tertunda (Pending Submissions) kelolaan
            $pendingVerificationsCount = ChecklistSubmission::where('status', SubmissionStatusEnum::SUBMITTED)
                ->whereHas('task', function ($q) use ($roomIds) {
                    $q->whereIn('room_id', $roomIds);
                })->count();

            // 5. Temuan Masalah Aktif kelolaan
            $activeFindingsCount = Finding::whereIn('status', [FindingStatusEnum::OPEN, FindingStatusEnum::IN_PROGRESS])
                ->whereIn('room_id', $roomIds)->count();

            // 6. Tugas Overdue Terbaru kelolaan
            $recentOverdueTasks = Task::where('status', TaskStatusEnum::OVERDUE)
                ->whereIn('room_id', $roomIds)
                ->whereBetween('tanggal_task', [$dateFrom, $dateTo])
                ->with(['room', 'cs'])
                ->orderBy('due_datetime', 'desc')
                ->take(5)
                ->get()
                ->map(fn($t) => [
                    'task_id' => $t->id,
                    'room_name' => $t->room?->nama_ruangan,
                    'cs_name' => $t->cs?->full_name ?? 'Belum Ditugaskan',
                    'due_datetime' => $t->due_datetime?->toDateTimeString(),
                    'task_date' => $t->tanggal_task->toDateString(),
                ]);

            return [
                'total_buildings' => $totalBuildings,
                'total_rooms' => $totalRooms,
                'compliance_rate' => $complianceRate,
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'breakdown_per_building' => $buildingsData,
                'pending_verifications' => $pendingVerificationsCount,
                'active_findings' => $activeFindingsCount,
                'overdue_tasks' => $recentOverdueTasks,
            ];
        });

        return $this->success($data, 'Data dashboard PIC berhasil diambil.');
    }

    /**
     * Dashboard untuk Cleaning Service (CS).
     * Menampilkan status tugas pribadi hari ini dan tugas mendesak (<60 menit).
     */
    public function cs(Request $request)
    {
        $user = $request->user();

        // 1. Status Tugas CS hari ini
        $tasksToday = Task::where('cs_user_id', $user->id)
            ->whereDate('tanggal_task', today()->toDateString())
            ->get();

        $summary = [
            'total' => $tasksToday->count(),
            'pending' => $tasksToday->where('status', TaskStatusEnum::PENDING)->count(),
            'in_progress' => $tasksToday->where('status', TaskStatusEnum::IN_PROGRESS)->count(),
            'waiting_verification' => $tasksToday->where('status', TaskStatusEnum::WAITING_VERIFICATION)->count(),
            'completed' => $tasksToday->where('status', TaskStatusEnum::COMPLETED)->count(),
            'rejected' => $tasksToday->where('status', TaskStatusEnum::REJECTED)->count(),
            'overdue' => $tasksToday->where('status', TaskStatusEnum::OVERDUE)->count(),
        ];

        // 2. Tugas yang mendekati batas waktu pengerjaan (deadline < 60 menit)
        $now = now();
        $oneHourLater = (clone $now)->addMinutes(60);

        $urgentTasks = Task::where('cs_user_id', $user->id)
            ->whereDate('tanggal_task', today()->toDateString())
            ->whereIn('status', [TaskStatusEnum::PENDING, TaskStatusEnum::IN_PROGRESS])
            ->whereBetween('due_datetime', [$now, $oneHourLater])
            ->with('room')
            ->get()
            ->map(fn($t) => [
                'task_id' => $t->id,
                'room_name' => $t->room?->nama_ruangan,
                'room_code' => $t->room?->kode_ruangan,
                'due_datetime' => $t->due_datetime->toDateTimeString(),
                'minutes_left' => round($now->diffInMinutes($t->due_datetime)),
            ]);

        return $this->success([
            'tasks_summary' => $summary,
            'urgent_tasks' => $urgentTasks,
        ], 'Data dashboard CS berhasil diambil.');
    }

    /**
     * Dashboard untuk Office Boy (OB).
     * Menampilkan ringkasan temuan kerusakan yang ditugaskan ke OB ini.
     */
    public function ob(Request $request)
    {
        $user = $request->user();

        // Cari findings yang ditugaskan ke OB ini
        $myFindings = Finding::where('assigned_to', $user->id)->get();

        $openCount = $myFindings->where('status', FindingStatusEnum::OPEN)->count();
        $inProgressCount = $myFindings->where('status', FindingStatusEnum::IN_PROGRESS)->count();
        $resolvedCount = $myFindings->where('status', FindingStatusEnum::RESOLVED)->count();
        
        $overdueCount = $myFindings->filter(function ($f) {
            return $f->status !== FindingStatusEnum::RESOLVED && 
                   $f->deadline_perbaikan && 
                   $f->deadline_perbaikan->isPast();
        })->count();

        $summary = [
            'total' => $myFindings->count(),
            'open' => $openCount,
            'in_progress' => $inProgressCount,
            'resolved' => $resolvedCount,
            'overdue' => $overdueCount,
        ];

        // Temuan aktif terbaru yang ditugaskan (limit 5)
        $recentFindings = Finding::where('assigned_to', $user->id)
            ->whereIn('status', [FindingStatusEnum::OPEN, FindingStatusEnum::IN_PROGRESS])
            ->with(['room.building'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(fn($f) => [
                'id' => $f->id,
                'room_name' => $f->room?->nama_ruangan,
                'building_name' => $f->room?->building?->nama_gedung,
                'deskripsi' => $f->deskripsi,
                'prioritas' => $f->prioritas?->value,
                'deadline' => $f->deadline_perbaikan?->toDateString(),
                'is_overdue' => $f->deadline_perbaikan ? $f->deadline_perbaikan->isPast() : false,
            ]);

        return $this->success([
            'findings_summary' => $summary,
            'recent_findings' => $recentFindings,
        ], 'Data dashboard OB berhasil diambil.');
    }

    /**
     * Tampilkan detail status kebersihan seluruh ruangan dalam satu gedung terpilih per-shift kerja hari ini.
     */
    public function buildingDetails(Request $request, $id)
    {
        $building = Building::findOrFail($id);

        // Ambil semua ruangan aktif di gedung ini
        $rooms = Room::where('building_id', $building->id)->where('is_active', true)->get();

        // Ambil semua shift aktif
        $shifts = Shift::where('is_active', true)->get();

        // Ambil semua tugas hari ini di gedung ini
        $tasksToday = Task::whereIn('room_id', $rooms->pluck('id'))
            ->whereDate('tanggal_task', today()->toDateString())
            ->with('cs')
            ->get();

        $grid = $rooms->map(function ($room) use ($shifts, $tasksToday) {
            $shiftStatus = [];
            foreach ($shifts as $shift) {
                // Cari tugas untuk ruangan & shift ini hari ini
                $task = $tasksToday->where('room_id', $room->id)->where('shift_id', $shift->id)->first();

                $shiftStatus[$shift->nama_shift] = [
                    'task_id' => $task?->id,
                    'status' => $task ? $task->status->value : 'no_schedule',
                    'cs_name' => $task?->cs?->full_name ?? '-',
                ];
            }

            return [
                'room_id' => $room->id,
                'room_name' => $room->nama_ruangan,
                'room_code' => $room->kode_ruangan,
                'floor' => $room->lantai,
                'shifts' => $shiftStatus,
            ];
        });

        return $this->success([
            'building' => [
                'id' => $building->id,
                'name' => $building->nama_gedung,
                'code' => $building->kode_gedung,
            ],
            'rooms_cleanliness_grid' => $grid,
        ], 'Detail kebersihan gedung berhasil diambil.');
    }
}
