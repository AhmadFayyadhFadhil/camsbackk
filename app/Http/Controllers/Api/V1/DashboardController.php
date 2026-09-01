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

            // 3. Breakdown Kepatuhan per Gedung (Single Consolidated SQL Query)
            $buildingsRaw = DB::table('buildings as b')
                ->leftJoin('rooms as r', function ($join) {
                    $join->on('r.building_id', '=', 'b.id')
                         ->where('r.is_active', '=', 1)
                         ->whereNull('r.deleted_at');
                })
                ->leftJoin('tasks as t', function ($join) use ($dateFrom, $dateTo) {
                    $join->on('t.room_id', '=', 'r.id')
                         ->whereBetween('t.tanggal_task', [$dateFrom, $dateTo]);
                })
                ->where('b.is_active', true)
                ->whereNull('b.deleted_at')
                ->select(
                    'b.id as building_id',
                    'b.nama_gedung as building_name',
                    'b.kode_gedung as building_code',
                    'b.latitude',
                    'b.longitude',
                    'b.radius_meter',
                    DB::raw('COUNT(DISTINCT r.id) as total_rooms'),
                    DB::raw('COUNT(t.id) as total_tasks'),
                    DB::raw('SUM(CASE WHEN t.status = "completed" THEN 1 ELSE 0 END) as completed_tasks')
                )
                ->groupBy('b.id', 'b.nama_gedung', 'b.kode_gedung', 'b.latitude', 'b.longitude', 'b.radius_meter')
                ->orderBy('b.nama_gedung')
                ->get();

            $buildingsData = $buildingsRaw->map(function ($b) {
                $totalTasks = (int) $b->total_tasks;
                $completed = (int) $b->completed_tasks;
                $compliance = $totalTasks > 0 ? round(($completed / $totalTasks) * 100, 2) : 0.0;

                return [
                    'building_id' => $b->building_id,
                    'building_name' => $b->building_name,
                    'building_code' => $b->building_code,
                    'latitude' => $b->latitude !== null ? (float)$b->latitude : null,
                    'longitude' => $b->longitude !== null ? (float)$b->longitude : null,
                    'radius_meter' => (int)($b->radius_meter ?? 250),
                    'total_rooms' => (int) $b->total_rooms,
                    'total_tasks' => $totalTasks,
                    'completed_tasks' => $completed,
                    'compliance_rate' => $compliance,
                ];
            });

            // 4. Antrean Verifikasi Tertunda (Pending Submissions)
            $pendingVerificationsCount = ChecklistSubmission::where('status', SubmissionStatusEnum::SUBMITTED)->count();

            // 5. Temuan Masalah Aktif (status open & in_progress)
            $activeFindingsCount = Finding::whereIn('status', [FindingStatusEnum::OPEN, FindingStatusEnum::IN_PROGRESS])->count();

            // 6. Tugas Overdue Terbaru (limit 5)
            $recentOverdueTasks = Task::where('status', TaskStatusEnum::OVERDUE)
                ->whereBetween('tanggal_task', [$dateFrom, $dateTo])
                ->with([
                    'room:id,nama_ruangan,kode_ruangan',
                    'cs:id,full_name,username'
                ])
                ->orderBy('due_datetime', 'desc')
                ->take(5)
                ->get()
                ->map(fn($t) => [
                    'task_id' => $t->id,
                    'room_name' => $t->room?->nama_ruangan,
                    'cs_name' => $t->cs?->full_name ?? 'Belum Ditugaskan',
                    'due_datetime' => $t->due_datetime?->toDateTimeString(),
                    'task_date' => $t->tanggal_task instanceof Carbon ? $t->tanggal_task->toDateString() : (string) $t->tanggal_task,
                ]);

            // 7. Jejak Inspeksi Fisik Hari Ini (Real Inspection Trail dari Database)
            $inspectionTrail = ChecklistSubmission::whereHas('task', function($q) use ($dateFrom, $dateTo) {
                    $q->whereBetween('tanggal_task', [$dateFrom, $dateTo]);
                })
                ->whereIn('status', [SubmissionStatusEnum::SUBMITTED, SubmissionStatusEnum::APPROVED])
                ->with(['task.room.building', 'cs:id,full_name,username', 'latestVerification'])
                ->orderBy('submitted_at', 'desc')
                ->take(20)
                ->get()
                ->map(function($sub) {
                    $room = $sub->task?->room;
                    $building = $room?->building;
                    $subTime = $sub->submitted_at ? $sub->submitted_at->setTimezone(new \DateTimeZone(config('app.timezone', 'Asia/Jakarta')))->format('H:i') . ' WIB' : '-';
                    return [
                        'id' => $sub->id,
                        'room_id' => $room?->id,
                        'room_name' => $room?->nama_ruangan,
                        'room_code' => $room?->kode_ruangan,
                        'building_id' => $building?->id,
                        'building_name' => $building?->nama_gedung,
                        'building_lat' => $building?->latitude !== null ? (float)$building->latitude : null,
                        'building_lng' => $building?->longitude !== null ? (float)$building->longitude : null,
                        'building_radius' => (int)($building?->radius_meter ?? 250),
                        'cs_name' => $sub->cs?->full_name ?? 'Petugas CS',
                        'time' => $subTime,
                        'status' => $sub->status === SubmissionStatusEnum::APPROVED ? 'Terverifikasi On-Site' : 'Menunggu Verifikasi',
                        'is_approved' => $sub->status === SubmissionStatusEnum::APPROVED,
                    ];
                });

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
                'inspection_trail' => $inspectionTrail,
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

            // 3. Breakdown per Gedung (Single Consolidated SQL Query terbatas ruangan kelolaan)
            $buildingsRaw = DB::table('buildings as b')
                ->join('rooms as r', function ($join) use ($roomIds) {
                    $join->on('r.building_id', '=', 'b.id')
                         ->whereIn('r.id', $roomIds)
                         ->where('r.is_active', '=', 1)
                         ->whereNull('r.deleted_at');
                })
                ->leftJoin('tasks as t', function ($join) use ($dateFrom, $dateTo) {
                    $join->on('t.room_id', '=', 'r.id')
                         ->whereBetween('t.tanggal_task', [$dateFrom, $dateTo]);
                })
                ->where('b.is_active', true)
                ->whereNull('b.deleted_at')
                ->select(
                    'b.id as building_id',
                    'b.nama_gedung as building_name',
                    'b.kode_gedung as building_code',
                    DB::raw('COUNT(DISTINCT r.id) as total_rooms'),
                    DB::raw('COUNT(t.id) as total_tasks'),
                    DB::raw('SUM(CASE WHEN t.status = "completed" THEN 1 ELSE 0 END) as completed_tasks')
                )
                ->groupBy('b.id', 'b.nama_gedung', 'b.kode_gedung')
                ->orderBy('b.nama_gedung')
                ->get();

            $buildingsData = $buildingsRaw->map(function ($b) {
                $totalTasks = (int) $b->total_tasks;
                $completed = (int) $b->completed_tasks;
                $compliance = $totalTasks > 0 ? round(($completed / $totalTasks) * 100, 2) : 0.0;

                return [
                    'building_id' => $b->building_id,
                    'building_name' => $b->building_name,
                    'building_code' => $b->building_code,
                    'total_rooms' => (int) $b->total_rooms,
                    'total_tasks' => $totalTasks,
                    'completed_tasks' => $completed,
                    'compliance_rate' => $compliance,
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
                ->with([
                    'room:id,nama_ruangan,kode_ruangan',
                    'cs:id,full_name,username'
                ])
                ->orderBy('due_datetime', 'desc')
                ->take(5)
                ->get()
                ->map(fn($t) => [
                    'task_id' => $t->id,
                    'room_name' => $t->room?->nama_ruangan,
                    'cs_name' => $t->cs?->full_name ?? 'Belum Ditugaskan',
                    'due_datetime' => $t->due_datetime?->toDateTimeString(),
                    'task_date' => $t->tanggal_task instanceof Carbon ? $t->tanggal_task->toDateString() : (string) $t->tanggal_task,
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
        $today = today()->toDateString();

        // 1. Dapatkan ID gedung tempat CS ditugaskan hari ini
        $buildingIds = \App\Models\CsAssignment::where('cs_user_id', $user->id)
            ->where('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('tanggal_selesai')
                      ->orWhere('tanggal_selesai', '>=', $today);
            })
            ->pluck('building_id')
            ->toArray();

        // 2. Ambil seluruh tugas di gedung penugasan aktif hari ini (menggunakan index langsung pada tanggal_task)
        $tasksToday = Task::where('tanggal_task', $today)
            ->whereHas('room', function ($q) use ($buildingIds) {
                $q->whereIn('building_id', $buildingIds);
            })
            ->with([
                'room:id,nama_ruangan,kode_ruangan,building_id',
                'cs:id,full_name,username'
            ])
            ->get();

        // 3. Kelompokkan per Ruangan + Shift + Tanggal (representasi 1 area/ruangan fisik)
        $groupedTasks = $tasksToday->groupBy(function($task) {
            $taskDate = $task->tanggal_task instanceof Carbon ? $task->tanggal_task->toDateString() : (string) $task->tanggal_task;
            return $task->room_id . '_' . $task->shift_id . '_' . $taskDate;
        })->map(function($taskGroup) {
            $statusPriority = [
                'in_progress' => 1,
                'rejected' => 2,
                'overdue' => 3,
                'waiting_verification' => 4,
                'pending' => 5,
                'completed' => 6,
            ];
            $sortedGroup = $taskGroup->sortBy(function($t) use ($statusPriority) {
                return $statusPriority[$t->status->value] ?? 99;
            });
            return $sortedGroup->first();
        });

        $summary = [
            'total' => $groupedTasks->count(),
            'pending' => $groupedTasks->where('status', TaskStatusEnum::PENDING)->count(),
            'in_progress' => $groupedTasks->where('status', TaskStatusEnum::IN_PROGRESS)->count(),
            'waiting_verification' => $groupedTasks->where('status', TaskStatusEnum::WAITING_VERIFICATION)->count(),
            'completed' => $groupedTasks->where('status', TaskStatusEnum::COMPLETED)->count(),
            'rejected' => $groupedTasks->where('status', TaskStatusEnum::REJECTED)->count(),
            'overdue' => $groupedTasks->where('status', TaskStatusEnum::OVERDUE)->count(),
        ];

        // 4. Tugas mendesak per ruangan yang mendekati batas waktu pengerjaan (deadline < 60 menit)
        $now = now();
        $oneHourLater = (clone $now)->addMinutes(60);

        $urgentTasks = $groupedTasks->filter(function($t) use ($now, $oneHourLater) {
            return in_array($t->status, [TaskStatusEnum::PENDING, TaskStatusEnum::IN_PROGRESS]) &&
                   $t->due_datetime && 
                   $t->due_datetime >= $now && 
                   $t->due_datetime <= $oneHourLater;
        })->values()->map(fn($t) => [
            'task_id' => $t->id,
            'room_name' => $t->room?->nama_ruangan,
            'room_code' => $t->room?->kode_ruangan,
            'due_datetime' => $t->due_datetime?->toDateTimeString(),
            'minutes_left' => round($now->diffInMinutes($t->due_datetime)),
        ]);

        // 5. Data Gedung Penugasan CS beserta koordinat dan radius Geofence
        $assignedBuildings = Building::whereIn('id', $buildingIds)
            ->where('is_active', true)
            ->get()
            ->map(fn($b) => [
                'id' => $b->id,
                'name' => $b->nama_gedung,
                'code' => $b->kode_gedung,
                'latitude' => $b->latitude !== null ? (float)$b->latitude : null,
                'longitude' => $b->longitude !== null ? (float)$b->longitude : null,
                'radius_meter' => (int)($b->radius_meter ?? 250),
            ]);

        return $this->success([
            'tasks_summary' => $summary,
            'urgent_tasks' => $urgentTasks,
            'assigned_buildings' => $assignedBuildings,
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

        // Ambil semua tugas hari ini di gedung ini (menggunakan index pada tanggal_task)
        $tasksToday = Task::whereIn('room_id', $rooms->pluck('id'))
            ->where('tanggal_task', today()->toDateString())
            ->with('cs:id,full_name,username')
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
