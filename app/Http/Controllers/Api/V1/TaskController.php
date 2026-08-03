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
        if (!Task::whereDate('tanggal_task', today()->toDateString())->exists()) {
            $generator = new \App\Services\TaskGeneratorService();
            $generator->generateForDate(today());
        }

        // Dapatkan semua ID gedung tempat CS ditugaskan hari ini
        $today = today()->toDateString();
        $buildingIds = \App\Models\CsAssignment::where('cs_user_id', $user->id)
            ->where('tanggal_mulai', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('tanggal_selesai')
                      ->orWhere('tanggal_selesai', '>=', $today);
            })
            ->pluck('building_id')
            ->toArray();

        $query = Task::query()
            ->with(['schedule.checklistItem', 'cs', 'room.building', 'shift'])
            ->whereDate('tanggal_task', today()->toDateString())
            ->where(function ($q) use ($user, $buildingIds) {
                // Tugas milik CS ini
                $q->where('cs_user_id', $user->id);
                
                // ATAU tugas belum teralokasi, tapi berada di gedung penugasan CS ini
                if (!empty($buildingIds)) {
                    $q->orWhere(function ($sub) use ($buildingIds) {
                        $sub->whereNull('cs_user_id')
                            ->whereIn('room_id', function ($roomQuery) use ($buildingIds) {
                                $roomQuery->select('id')
                                    ->from('rooms')
                                    ->whereIn('building_id', $buildingIds);
                            });
                    });
                }
            });

        $perPage = $request->get('per_page', 1000);
        $tasks = $query->paginate($perPage);

        return $this->paginated(TaskResource::collection($tasks), 'Daftar tugas Anda hari ini berhasil diambil.');
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
