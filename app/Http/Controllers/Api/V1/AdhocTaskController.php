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

        if ($request->has('task_type') && $request->filled('task_type')) {
            $query->where('task_type', $request->get('task_type'));
        }

        if ($request->has('status') && $request->filled('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->has('priority') && $request->filled('priority')) {
            $query->where('priority', $request->get('priority'));
        }

        if ($request->has('building_id') && $request->filled('building_id')) {
            $buildingId = $request->get('building_id');
            $query->whereHas('room', function ($rq) use ($buildingId) {
                $rq->where('building_id', $buildingId);
            });
        }

        if ($request->has('search') && $request->filled('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('judul', 'like', "%{$search}%")
                  ->orWhere('deskripsi', 'like', "%{$search}%")
                  ->orWhereHas('room', function ($rq) use ($search) {
                      $rq->where('nama_ruangan', 'like', "%{$search}%")
                         ->orWhere('kode_ruangan', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('cs_user_id') && !$user->hasRole(RoleEnum::CS)) {
            $query->where('cs_user_id', $request->get('cs_user_id'));
        }

        $query->orderByRaw("FIELD(status, 'pending', 'in_progress', 'submitted', 'verified', 'rejected')")
              ->orderBy('due_datetime', 'asc')
              ->orderBy('created_at', 'desc');

        $perPage = $request->get('per_page', 30);
        $tasks = $query->paginate($perPage);

        return $this->paginated(
            AdhocTaskResource::collection($tasks),
            'Daftar tugas khusus & terjadwal berhasil diambil.'
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
            'task_type' => ['nullable', 'string', 'in:immediate,scheduled_event'],
            'due_datetime' => ['nullable', 'date'],
            'event_start_time' => ['nullable', 'date'],
            'checklist_items' => ['nullable', 'array'],
        ]);

        $taskType = $request->input('task_type', 'immediate');
        $checklistItems = $request->input('checklist_items', []);

        // Normalize checklist items structure
        if (is_array($checklistItems)) {
            $checklistItems = array_map(function ($item, $idx) {
                if (is_string($item)) {
                    return [
                        'id' => $idx + 1,
                        'task' => trim($item),
                        'is_done' => false,
                        'done_at' => null,
                    ];
                }
                return [
                    'id' => $item['id'] ?? ($idx + 1),
                    'task' => $item['task'] ?? '',
                    'is_done' => !empty($item['is_done']),
                    'done_at' => $item['done_at'] ?? null,
                ];
            }, $checklistItems, array_keys($checklistItems));
        }

        $task = AdhocTask::create([
            'id' => (string) Str::uuid(),
            'created_by' => $request->user()->id,
            'cs_user_id' => $request->cs_user_id,
            'room_id' => $request->room_id,
            'judul' => $request->judul,
            'deskripsi' => $request->deskripsi,
            'priority' => $request->input('priority', 'medium'),
            'task_type' => $taskType,
            'due_datetime' => $request->due_datetime ? date('Y-m-d H:i:s', strtotime($request->due_datetime)) : null,
            'event_start_time' => $request->event_start_time ? date('Y-m-d H:i:s', strtotime($request->event_start_time)) : null,
            'checklist_items' => $checklistItems,
            'status' => 'pending',
        ]);

        $task->load(['creator', 'cs', 'room.building']);

        // Kirim notifikasi real-time ke CS
        $notifTitle = $taskType === 'scheduled_event' 
            ? "Tugas Terjadwal / Persiapan Acara: {$task->judul}"
            : "Tugas Mendadak: {$task->judul}";

        $notifMsg = $taskType === 'scheduled_event'
            ? "Supervisor {$request->user()->full_name} menugaskan persiapan acara/meeting: {$task->judul}. Target Selesai: " . ($task->due_datetime ? $task->due_datetime->format('d M Y H:i') : 'Sesuai Jadwal')
            : "Supervisor {$request->user()->full_name} memberikan tugas mendadak: {$task->judul}. Prioritas: " . strtoupper($task->priority);

        NotificationService::send(
            $task->cs_user_id,
            'ADHOC_TASK_ASSIGNED',
            $notifTitle,
            $notifMsg,
            [
                'adhoc_task_id' => $task->id,
                'judul' => $task->judul,
                'task_type' => $task->task_type,
                'priority' => $task->priority,
            ],
            'both'
        );

        AuditLogService::log('CREATE_ADHOC_TASK', 'adhoc_tasks', $task->id, null, $task->toArray());

        return $this->success(new AdhocTaskResource($task), 'Tugas khusus / terjadwal berhasil dibuat dan ditugaskan ke CS.', 201);
    }

    /**
     * PUT /adhoc-tasks/{id} (admin, supervisor)
     */
    public function update(Request $request, $id)
    {
        $task = AdhocTask::findOrFail($id);

        $request->validate([
            'cs_user_id' => ['sometimes', 'uuid', 'exists:users,id'],
            'room_id' => ['nullable', 'uuid', 'exists:rooms,id'],
            'judul' => ['sometimes', 'string', 'max:255'],
            'deskripsi' => ['sometimes', 'string'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
            'task_type' => ['nullable', 'string', 'in:immediate,scheduled_event'],
            'due_datetime' => ['nullable', 'date'],
            'event_start_time' => ['nullable', 'date'],
            'checklist_items' => ['nullable', 'array'],
        ]);

        $oldData = $task->toArray();

        $updateData = [];
        if ($request->has('cs_user_id')) $updateData['cs_user_id'] = $request->cs_user_id;
        if ($request->has('room_id')) $updateData['room_id'] = $request->room_id;
        if ($request->has('judul')) $updateData['judul'] = $request->judul;
        if ($request->has('deskripsi')) $updateData['deskripsi'] = $request->deskripsi;
        if ($request->has('priority')) $updateData['priority'] = $request->priority;
        if ($request->has('task_type')) $updateData['task_type'] = $request->task_type;
        if ($request->has('due_datetime')) $updateData['due_datetime'] = $request->due_datetime ? date('Y-m-d H:i:s', strtotime($request->due_datetime)) : null;
        if ($request->has('event_start_time')) $updateData['event_start_time'] = $request->event_start_time ? date('Y-m-d H:i:s', strtotime($request->event_start_time)) : null;
        
        if ($request->has('checklist_items')) {
            $checklistItems = $request->input('checklist_items', []);
            if (is_array($checklistItems)) {
                $checklistItems = array_map(function ($item, $idx) {
                    if (is_string($item)) {
                        return [
                            'id' => $idx + 1,
                            'task' => trim($item),
                            'is_done' => false,
                            'done_at' => null,
                        ];
                    }
                    return [
                        'id' => $item['id'] ?? ($idx + 1),
                        'task' => $item['task'] ?? '',
                        'is_done' => !empty($item['is_done']),
                        'done_at' => $item['done_at'] ?? null,
                    ];
                }, $checklistItems, array_keys($checklistItems));
            }
            $updateData['checklist_items'] = $checklistItems;
        }

        $task->update($updateData);

        AuditLogService::log('UPDATE_ADHOC_TASK', 'adhoc_tasks', $task->id, $oldData, $task->toArray());

        return $this->success(new AdhocTaskResource($task->load(['creator', 'cs', 'room.building'])), 'Data penugasan berhasil diperbarui.');
    }

    /**
     * DELETE /adhoc-tasks/{id} (admin, supervisor)
     */
    public function destroy($id)
    {
        $task = AdhocTask::findOrFail($id);
        $oldData = $task->toArray();
        $task->delete();

        AuditLogService::log('DELETE_ADHOC_TASK', 'adhoc_tasks', $task->id, $oldData, null);

        return $this->success(null, 'Tugas khusus / terjadwal berhasil dihapus.');
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

        return $this->success(new AdhocTaskResource($task->load(['creator', 'cs', 'room.building'])), 'Tugas dimulai. Tugas harian di-pause sementara.');
    }

    /**
     * POST /adhoc-tasks/{id}/submit (cs) - Umum / Backward Compatibility
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
            'checklist_items' => ['nullable', 'array'],
        ]);

        $fotoBinary = file_get_contents($request->file('foto_bukti')->getRealPath());
        $mimeType = $request->file('foto_bukti')->getMimeType();

        $updateData = [
            'status' => 'submitted',
            'stage' => 'completed',
            'foto_bukti' => $fotoBinary,
            'foto_bukti_mime' => $mimeType,
            'submitted_at' => now(),
            'setup_submitted_at' => now(),
        ];

        if ($request->has('checklist_items')) {
            $checklistItems = $request->input('checklist_items');
            if (is_array($checklistItems)) {
                $updateData['checklist_items'] = $checklistItems;
            }
        }

        $oldData = $task->toArray();
        $task->update($updateData);

        // Notifikasi ke pembuat tugas (Supervisor)
        NotificationService::send(
            $task->created_by,
            'ADHOC_TASK_SUBMITTED',
            "Tugas Selesai: {$task->judul}",
            "CS {$user->full_name} telah menyelesaikan penugasan: {$task->judul} beserta bukti foto.",
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
            'Laporan penugasan berhasil dikirim.'
        );
    }

    /**
     * POST /adhoc-tasks/{id}/submit-setup (cs) - Tahap 1: Foto Bukti Persiapan Ruangan
     */
    public function submitSetup(Request $request, $id)
    {
        $task = AdhocTask::findOrFail($id);
        $user = $request->user();

        if ($task->cs_user_id !== $user->id && !$user->hasRole(RoleEnum::ADMIN) && !$user->hasRole(RoleEnum::SUPERVISOR)) {
            return $this->error('Akses ditolak. Tugas ini tidak ditugaskan kepada Anda.', [], 403);
        }

        $request->validate([
            'foto_bukti' => ['required', 'image', 'max:1024'], // 1MB limit
            'checklist_items' => ['nullable', 'array'],
        ]);

        $fotoBinary = file_get_contents($request->file('foto_bukti')->getRealPath());
        $mimeType = $request->file('foto_bukti')->getMimeType();

        $updateData = [
            'foto_bukti' => $fotoBinary,
            'foto_bukti_mime' => $mimeType,
            'setup_submitted_at' => now(),
        ];

        if ($task->task_type === 'scheduled_event' || $task->requires_cleanup) {
            $updateData['stage'] = 'setup_submitted';
            $updateData['status'] = 'in_progress';
        } else {
            $updateData['stage'] = 'completed';
            $updateData['status'] = 'submitted';
            $updateData['submitted_at'] = now();
        }

        if ($request->has('checklist_items')) {
            $checklistItems = $request->input('checklist_items');
            if (is_array($checklistItems)) {
                $updateData['checklist_items'] = $checklistItems;
            }
        }

        $oldData = $task->toArray();
        $task->update($updateData);

        // Notifikasi ke Supervisor
        NotificationService::send(
            $task->created_by,
            'ADHOC_TASK_SETUP_READY',
            "Ruang Meeting Siap: {$task->judul}",
            "CS {$user->full_name} telah selesai menyiapkan ruangan untuk acara: {$task->judul} beserta foto bukti persiapan.",
            [
                'adhoc_task_id' => $task->id,
                'cs_name' => $user->full_name,
                'judul' => $task->judul,
                'stage' => $task->stage,
            ],
            'both'
        );

        AuditLogService::log('SUBMIT_SETUP_ADHOC_TASK', 'adhoc_tasks', $task->id, $oldData, $task->toArray());

        return $this->success(
            new AdhocTaskResource($task->load(['creator', 'cs', 'room.building'])),
            'Laporan persiapan ruangan berhasil dikirim. Ruangan kini siap digunakan untuk meeting.'
        );
    }

    /**
     * POST /adhoc-tasks/{id}/submit-cleanup (cs) - Tahap 2: Foto Bukti Perapihan Pasca-Meeting
     */
    public function submitCleanup(Request $request, $id)
    {
        $task = AdhocTask::findOrFail($id);
        $user = $request->user();

        if ($task->cs_user_id !== $user->id && !$user->hasRole(RoleEnum::ADMIN) && !$user->hasRole(RoleEnum::SUPERVISOR)) {
            return $this->error('Akses ditolak. Tugas ini tidak ditugaskan kepada Anda.', [], 403);
        }

        $request->validate([
            'foto_bukti_cleanup' => ['required', 'image', 'max:1024'], // 1MB limit
        ]);

        $fotoBinary = file_get_contents($request->file('foto_bukti_cleanup')->getRealPath());
        $mimeType = $request->file('foto_bukti_cleanup')->getMimeType();

        $oldData = $task->toArray();
        $task->update([
            'foto_bukti_cleanup' => $fotoBinary,
            'foto_bukti_cleanup_mime' => $mimeType,
            'cleanup_submitted_at' => now(),
            'stage' => 'completed',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        // Notifikasi ke Supervisor
        NotificationService::send(
            $task->created_by,
            'ADHOC_TASK_CLEANUP_SUBMITTED',
            "Perapihan Selesai: {$task->judul}",
            "CS {$user->full_name} telah selesai merapikan ruangan pasca-meeting untuk tugas: {$task->judul}.",
            [
                'adhoc_task_id' => $task->id,
                'cs_name' => $user->full_name,
                'judul' => $task->judul,
            ],
            'both'
        );

        AuditLogService::log('SUBMIT_CLEANUP_ADHOC_TASK', 'adhoc_tasks', $task->id, $oldData, $task->toArray());

        return $this->success(
            new AdhocTaskResource($task->load(['creator', 'cs', 'room.building'])),
            'Laporan perapihan ruangan pasca-meeting berhasil dikirim ke Supervisor untuk verifikasi akhir.'
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
            'verification_notes' => $request->catatan,
            'verified_at' => now(),
        ]);

        // Notifikasi ke CS
        $statusLabel = $newStatus === 'verified' ? 'Disetujui / Selesai Tuntas' : 'Ditolak / Perlu Perbaikan';
        NotificationService::send(
            $task->cs_user_id,
            'ADHOC_TASK_VERIFIED',
            "Verifikasi Penugasan: {$task->judul} ({$statusLabel})",
            "Laporan tugas '{$task->judul}' telah diverifikasi oleh {$request->user()->full_name}. Status: {$statusLabel}." . ($request->catatan ? " Catatan: {$request->catatan}" : ""),
            [
                'adhoc_task_id' => $task->id,
                'status' => $newStatus,
                'catatan' => $request->catatan,
            ],
            'both'
        );

        AuditLogService::log('VERIFY_ADHOC_TASK', 'adhoc_tasks', $task->id, $oldData, $task->toArray());

        return $this->success(new AdhocTaskResource($task->load(['creator', 'cs', 'room.building'])), "Tugas berhasil di-{$newStatus}.");
    }

    /**
     * GET /adhoc-tasks/{id}/foto-bukti
     */
    public function streamFotoBukti($id)
    {
        return $this->streamFotoPersiapan($id);
    }

    /**
     * GET /adhoc-tasks/{id}/foto-persiapan (Foto 1)
     */
    public function streamFotoPersiapan($id)
    {
        $task = AdhocTask::findOrFail($id);

        if (!$task->foto_bukti) {
            return $this->error('Foto bukti persiapan tidak ditemukan.', [], 404);
        }

        $mimeType = $task->foto_bukti_mime ?? 'image/jpeg';

        return response()->stream(function () use ($task) {
            echo $task->foto_bukti;
        }, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="adhoc_setup_' . $task->id . '.jpg"',
        ]);
    }

    /**
     * GET /adhoc-tasks/{id}/foto-cleanup (Foto 2)
     */
    public function streamFotoCleanup($id)
    {
        $task = AdhocTask::findOrFail($id);

        if (!$task->foto_bukti_cleanup) {
            return $this->error('Foto bukti perapihan pasca-acara tidak ditemukan.', [], 404);
        }

        $mimeType = $task->foto_bukti_cleanup_mime ?? 'image/jpeg';

        return response()->stream(function () use ($task) {
            echo $task->foto_bukti_cleanup;
        }, 200, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'no-cache, private',
            'Content-Disposition' => 'inline; filename="adhoc_cleanup_' . $task->id . '.jpg"',
        ]);
    }
}
