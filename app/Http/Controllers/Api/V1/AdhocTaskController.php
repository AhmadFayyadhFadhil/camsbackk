<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdhocTaskResource;
use App\Models\AdhocTask;
use App\Models\User;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Enums\RoleEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdhocTaskController extends Controller
{
    use ApiResponse;

    /**
     * GET /adhoc-tasks
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = AdhocTask::query()->with(['creator', 'cs', 'room.building']);

        if ($user->hasRole(RoleEnum::CS)) {
            $query->where('cs_user_id', $user->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('priority')) {
            $query->where('priority', $request->get('priority'));
        }

        if ($request->has('cs_user_id') && !$user->hasRole(RoleEnum::CS)) {
            $query->where('cs_user_id', $request->get('cs_user_id'));
        }

        $query->orderByRaw("FIELD(status, 'pending', 'in_progress', 'submitted', 'verified', 'rejected')")
              ->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 20);
        $tasks = $query->paginate($perPage);

        return $this->paginated(
            AdhocTaskResource::collection($tasks),
            'Daftar tugas ad-hoc berhasil diambil.'
        );
    }

    /**
     * POST /adhoc-tasks (admin, supervisor)
     */
    public function store(Request $request)
    {
        $request->validate([
            'cs_user_id' => ['required', 'uuid', 'exists:users,id'],
            'room_id' => ['nullable', 'uuid', 'exists:rooms,id'],
            'judul' => ['required', 'string', 'max:255'],
            'deskripsi' => ['required', 'string'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
        ]);

        $task = AdhocTask::create([
            'id' => (string) Str::uuid(),
            'created_by' => $request->user()->id,
            'cs_user_id' => $request->cs_user_id,
            'room_id' => $request->room_id,
            'judul' => $request->judul,
            'deskripsi' => $request->deskripsi,
            'priority' => $request->input('priority', 'medium'),
            'status' => 'pending',
        ]);

        $task->load(['creator', 'cs', 'room.building']);

        // Kirim notifikasi real-time ke CS
        NotificationService::send(
            $task->cs_user_id,
            'ADHOC_TASK_ASSIGNED',
            "Tugas Mendadak: {$task->judul}",
            "Supervisor {$request->user()->full_name} memberikan tugas mendadak: {$task->judul}. Prioritas: " . strtoupper($task->priority),
            [
                'adhoc_task_id' => $task->id,
                'judul' => $task->judul,
                'priority' => $task->priority,
            ],
            'both'
        );

        AuditLogService::log('CREATE_ADHOC_TASK', 'adhoc_tasks', $task->id, null, $task->toArray());

        return $this->success(new AdhocTaskResource($task), 'Tugas ad-hoc berhasil dibuat dan ditugaskan ke CS.', 201);
    }

    /**
     * GET /adhoc-tasks/{id}
     */
    public function show($id)
    {
        $task = AdhocTask::with(['creator', 'cs', 'room.building'])->findOrFail($id);
        return $this->success(new AdhocTaskResource($task), 'Detail tugas ad-hoc berhasil diambil.');
    }

    /**
     * POST /adhoc-tasks/{id}/start (cs)
     */
    public function start(Request $request, $id)
    {
        $task = AdhocTask::findOrFail($id);
        $user = $request->user();

        if ($task->cs_user_id !== $user->id && !$user->hasRole(RoleEnum::ADMIN) && !$user->hasRole(RoleEnum::SUPERVISOR)) {
            return $this->error('Akses ditolak. Tugas ini tidak ditugaskan kepada Anda.', [], 403);
        }

        $oldData = $task->toArray();
        $task->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        AuditLogService::log('START_ADHOC_TASK', 'adhoc_tasks', $task->id, $oldData, $task->toArray());

        return $this->success(new AdhocTaskResource($task->load(['creator', 'cs', 'room.building'])), 'Tugas ad-hoc dimulai. Tugas harian di-pause sementara.');
    }

    /**
     * POST /adhoc-tasks/{id}/submit (cs)
     */
    public function submit(Request $request, $id)
    {
        $task = AdhocTask::findOrFail($id);
        $user = $request->user();

        if ($task->cs_user_id !== $user->id && !$user->hasRole(RoleEnum::ADMIN) && !$user->hasRole(RoleEnum::SUPERVISOR)) {
            return $this->error('Akses ditolak. Tugas ini tidak ditugaskan kepada Anda.', [], 403);
        }

        $request->validate([
            'foto_bukti' => ['required', 'image', 'max:1024'], // 1MB limit
        ]);

        $fotoBinary = file_get_contents($request->file('foto_bukti')->getRealPath());
        $mimeType = $request->file('foto_bukti')->getMimeType();

        $oldData = $task->toArray();
        $task->update([
            'status' => 'submitted',
            'foto_bukti' => $fotoBinary,
            'foto_bukti_mime' => $mimeType,
            'submitted_at' => now(),
        ]);

        // Notifikasi ke pembuat tugas (Supervisor)
        NotificationService::send(
            $task->created_by,
            'ADHOC_TASK_SUBMITTED',
            "Tugas Mendadak Selesai: {$task->judul}",
            "CS {$user->full_name} telah menyelesaikan tugas mendadak: {$task->judul} beserta bukti foto.",
            [
                'adhoc_task_id' => $task->id,
                'cs_name' => $user->full_name,
                'judul' => $task->judul,
            ],
            'both'
        );

        AuditLogService::log('SUBMIT_ADHOC_TASK', 'adhoc_tasks', $task->id, $oldData, $task->toArray());

        return $this->success(
            new AdhocTaskResource($task->load(['creator', 'cs', 'room.building'])),
            'Laporan tugas ad-hoc berhasil dikirim. Tugas harian Anda otomatis diaktifkan kembali (auto-resume).'
        );
    }

    /**
     * POST /adhoc-tasks/{id}/verify (admin, supervisor)
     */
    public function verify(Request $request, $id)
    {
        $task = AdhocTask::findOrFail($id);

        $request->validate([
            'status' => ['required', 'string', 'in:verified,rejected'],
            'catatan' => ['nullable', 'string'],
        ]);

        $oldData = $task->toArray();
        $newStatus = $request->status;

        $task->update([
            'status' => $newStatus,
            'verified_at' => now(),
        ]);

        // Notifikasi ke CS
        $statusLabel = $newStatus === 'verified' ? 'Disetujui' : 'Ditolak/Perlu Perbaikan';
        NotificationService::send(
            $task->cs_user_id,
            'ADHOC_TASK_VERIFIED',
            "Verifikasi Tugas Mendadak: {$task->judul} ({$statusLabel})",
            "Laporan tugas mendadak '{$task->judul}' telah diverifikasi oleh {$request->user()->full_name}. Status: {$statusLabel}." . ($request->catatan ? " Catatan: {$request->catatan}" : ""),
            [
                'adhoc_task_id' => $task->id,
                'status' => $newStatus,
                'catatan' => $request->catatan,
            ],
            'both'
        );

        AuditLogService::log('VERIFY_ADHOC_TASK', 'adhoc_tasks', $task->id, $oldData, $task->toArray());

        return $this->success(new AdhocTaskResource($task->load(['creator', 'cs', 'room.building'])), "Tugas ad-hoc berhasil di-{$newStatus}.");
    }

    /**
     * GET /adhoc-tasks/{id}/foto-bukti
     */
    public function streamFotoBukti($id)
    {
        $task = AdhocTask::findOrFail($id);

        if (!$task->foto_bukti) {
            return $this->error('Foto bukti tugas ad-hoc tidak ditemukan.', [], 404);
        }

        $mimeType = $task->foto_bukti_mime ?? 'image/jpeg';

        return response()->stream(function () use ($task) {
            echo $task->foto_bukti;
        }, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="adhoc_' . $task->id . '.jpg"',
        ]);
    }
}
