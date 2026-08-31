<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Services\TaskGeneratorService;
use App\Traits\ApiResponse;
use App\Enums\RoleEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Carbon\Carbon;

class TaskController extends Controller
{
    use ApiResponse;

    /**
     * Tampilkan daftar tugas dengan filter (paginated).
     */
    public function index(Request $request)
    {
        // Auto-generate daily tasks if none exist for today yet (useful for local development/first access)
        if (!Task::whereDate('tanggal_task', today()->toDateString())->exists()) {
            $generator = new \App\Services\TaskGeneratorService();
            $generator->generateForDate(today());
        }

        $user = $request->user();
        $query = Task::query()->with(['schedule.checklistItem', 'cs', 'room.building', 'shift']);

        // Scope berdasarkan Peran (Data Protection / Pencegahan IDOR)
        if ($user->hasRole(RoleEnum::CS)) {
            $query->where('cs_user_id', $user->id);
        } elseif ($user->hasRole(RoleEnum::PIC)) {
            $query->whereIn('room_id', function ($q) use ($user) {
                $q->select('room_id')
                  ->from('room_pic_histories')
                  ->where('user_id', $user->id)
                  ->where(function ($sub) {
                      $sub->whereNull('tanggal_selesai')
                          ->orWhere('tanggal_selesai', '>=', today()->toDateString());
                  });
            });
        }

        // Terapkan filter request jika disediakan
        if ($request->has('date')) {
            $query->whereDate('tanggal_task', $request->get('date'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('building_id')) {
            $buildingId = $request->get('building_id');
            $query->whereHas('room', function ($q) use ($buildingId) {
                $q->where('building_id', $buildingId);
            });
        }

        if ($request->has('cs_user_id') && !$user->hasRole(RoleEnum::CS)) {
            $query->where('cs_user_id', $request->get('cs_user_id'));
        }

        $perPage = $request->get('per_page', 20);
        $tasks = $query->paginate($perPage);

        return $this->paginated(TaskResource::collection($tasks), 'Daftar tugas berhasil diambil.');
    }

    /**
     * Tampilkan detail tugas tertentu.
     */
    public function show($id)
    {
        $task = Task::with(['schedule.checklistItem', 'cs', 'room.building', 'shift'])->findOrFail($id);

        Gate::authorize('view', $task);

        return $this->success(new TaskResource($task), 'Detail tugas berhasil diambil.');
    }

    /**
     * Tampilkan daftar tugas hari ini untuk CS yang sedang login.
     */
    public function myTasks(Request $request)
    {
        $user = $request->user();
        if (!$user->hasRole(RoleEnum::CS)) {
            return $this->error('Akses ditolak. Hanya Cleaning Service yang dapat melihat tugas mandiri.', [], 403);
        }

        // Auto-generate daily tasks if none exist for today yet (useful for local development/first access)
        $today = today()->toDateString();
        if (!Task::where('tanggal_task', $today)->exists()) {
            $generator = new \App\Services\TaskGeneratorService();
            $generator->generateForDate(today());
        }

        // Dapatkan semua ID gedung tempat CS ditugaskan hari ini
        $buildingIds = \App\Models\CsAssignment::where('cs_user_id', $user->id)
            ->where('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('tanggal_selesai')
                      ->orWhere('tanggal_selesai', '>=', $today);
            })
            ->pluck('building_id')
            ->toArray();

        if (empty($buildingIds)) {
            return $this->success([], 'Tidak ada penugasan gedung aktif untuk akun Anda hari ini.');
        }

        $query = Task::query()
            ->with([
                'schedule:id,room_id,checklist_item_id,shift_id,target_jam_mulai,target_jam_selesai',
                'schedule.checklistItem:id,nama_item',
                'cs:id,full_name,username',
                'room:id,nama_ruangan,kode_ruangan,building_id',
                'room.building:id,nama_gedung,kode_gedung',
                'shift'
            ])
            ->where('tanggal_task', $today)
            ->whereHas('room', function ($roomQuery) use ($buildingIds) {
                $roomQuery->whereIn('building_id', $buildingIds);
            });

        $rawTasks = $query->get();

        // Mengelompokkan tugas per Ruangan + Shift + Tanggal agar CS melihat 1 baris tugas per ruangan
        $groupedTasks = $rawTasks->groupBy(function($task) {
            $taskDate = $task->tanggal_task instanceof Carbon ? $task->tanggal_task->toDateString() : (string) $task->tanggal_task;
            return $task->room_id . '_' . $task->shift_id . '_' . $taskDate;
        })->map(function($taskGroup) {
            // Prioritas status: in_progress > rejected > overdue > waiting_verification > pending > completed
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

            $rep = $sortedGroup->first();
            $rep->items_count = $taskGroup->count();
            $rep->checklist_item_names = $taskGroup->map(function($t) {
                return $t->schedule?->checklistItem?->nama_item;
            })->filter()->values()->toArray();

            return $rep;
        })->sortBy(function($task) {
            $jam = $task->target_jam_mulai ?: ($task->schedule?->target_jam_mulai ?: '99:99');
            return $jam . '_' . ($task->room?->nama_ruangan ?? '');
        })->values();

        return $this->success(TaskResource::collection($groupedTasks), 'Daftar tugas Anda hari ini berhasil diambil.');
    }

    /**
     * Pemicu pembuatan tugas harian manual oleh Admin/Supervisor.
     */
    public function generateManual(Request $request, TaskGeneratorService $taskGeneratorService)
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $dateStr = $request->input('date', today()->toDateString());
        $targetDate = Carbon::parse($dateStr);

        $results = $taskGeneratorService->generateForDate($targetDate);

        return $this->success(
            $results,
            'Pembuatan tugas secara manual berhasil diproses.'
        );
    }
}
