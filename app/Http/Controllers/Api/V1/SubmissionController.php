<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChecklistSubmissionResource;
use App\Models\Room;
use App\Models\Task;
use App\Models\ChecklistSubmission;
use App\Models\ChecklistResult;
use App\Models\ChecklistItem;
use App\Traits\ApiResponse;
use App\Enums\TaskStatusEnum;
use App\Enums\SubmissionStatusEnum;
use App\Services\NotificationService;
use App\Services\AuditLogService;
use App\Helpers\ShiftValidatorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SubmissionController extends Controller
{
    use ApiResponse;

    /**
     * Scan QR Code ruangan untuk memulai pengerjaan task.
     * POST /submissions/scan
     */
    public function scanQrCode(Request $request)
    {
        $request->validate([
            'room_id' => ['required', 'uuid'],
            'qr_code_token' => ['nullable', 'string'],
            'task_id' => ['nullable', 'uuid'],
        ]);

        $user = $request->user();

        $task = null;
        if ($request->task_id) {
            $task = Task::find($request->task_id);
        }

        // Cek apakah user sedang melanjutkan tugas yang memang sudah "in_progress" milik dirinya sendiri
        $isResuming = $task && 
                      $task->status === TaskStatusEnum::IN_PROGRESS && 
                      $task->cs_user_id === $user->id;

        $room = null;

        if ($isResuming) {
            // Jika melanjutkan tugas yang sudah dimulai, abaikan pengecekan token QR
            $room = $task->room;
        } else {
            // Jika memulai tugas baru (dari PENDING) atau tanpa detail tugas, wajib validasi token QR
            if (!$request->qr_code_token) {
                return $this->error('Token QR Code wajib dikirim untuk memulai pengerjaan.', [], 422);
            }

            $room = Room::where('id', $request->room_id)
                ->where('qr_code_token', $request->qr_code_token)
                ->first();

            // Fallback 1 (HANYA di env lokal untuk testing): jika token salah tapi task_id dikirim
            if (!$room && $request->task_id && config('app.env') === 'local') {
                if ($task) {
                    $room = $task->room;
                }
            }

            // Fallback 2: mencocokkan ruangan hanya berdasarkan token QR saja
            if (!$room) {
                $room = Room::where('qr_code_token', $request->qr_code_token)->first();
            }

            // Fallback 3 (HANYA di env lokal): mencocokkan ruangan berdasarkan ID saja
            if (!$room && config('app.env') === 'local') {
                $room = Room::find($request->room_id);
            }

            // Fallback 4 (HANYA di env lokal): mencocokkan dengan ruangan dari tugas pending pertama
            if (!$room && config('app.env') === 'local') {
                $todayStr = today()->toDateString();
                $firstTask = Task::where('cs_user_id', $user->id)
                    ->where('status', TaskStatusEnum::PENDING)
                    ->whereDate('tanggal_task', $todayStr)
                    ->first();
                
                if (!$firstTask) {
                    $firstTask = Task::where('status', TaskStatusEnum::PENDING)
                        ->whereDate('tanggal_task', $todayStr)
                        ->first();
                }

                if ($firstTask) {
                    $room = $firstTask->room;
                }
            }
        }

        if (!$room) {
            return $this->error('QR Code tidak valid atau ruangan tidak ditemukan.', [], 404);
        }

        // 1. Validasi CS ditugaskan di gedung ini hari ini
        $todayStr = today()->toDateString();
        $isAssigned = \App\Models\CsAssignment::where('cs_user_id', $user->id)
            ->where('building_id', $room->building_id)
            ->where('tanggal_mulai', '<=', $todayStr)
            ->where(function ($q) use ($todayStr) {
                $q->whereNull('tanggal_selesai')
                  ->orWhere('tanggal_selesai', '>=', $todayStr);
            })
            ->exists();

        if (!$isAssigned) {
            return $this->error('Akses ditolak: Anda tidak ditugaskan di gedung ini hari ini.', [], 403);
        }

        // 2. Deteksi shift otomatis berdasarkan waktu scan saat ini
        $currentShift = ShiftValidatorHelper::getCurrentShift();
        if (!$currentShift) {
            return $this->error('Akses ditolak: di luar jam shift kerja apa pun.', [], 403);
        }

        // 3. Cari semua tugas untuk ruangan ini hari ini
        $allTasksToday = Task::where('room_id', $room->id)
            ->where(function ($q) {
                $q->whereDate('tanggal_task', today()->toDateString());
                if (now()->format('H:i:s') <= '06:30:00') {
                    $q->orWhereDate('tanggal_task', today()->subDay()->toDateString());
                }
            })
            ->with('shift')
            ->get();

        // Saring tugas yang jam kerjanya sesuai dengan waktu sekarang (termasuk buffer)
        $activeShiftTasks = $allTasksToday->filter(function ($task) {
            return $task->shift && ShiftValidatorHelper::isWithinShift($task->shift);
        });

        if ($activeShiftTasks->isEmpty()) {
            return $this->error('Tidak ada jadwal tugas kebersihan untuk ruangan ini pada jam sekarang.', [], 404);
        }

        // Saring tugas yang bisa dikerjakan oleh user ini (status PENDING, atau IN_PROGRESS oleh user ini)
        $tasks = $activeShiftTasks->filter(function ($task) use ($user) {
            return $task->status === TaskStatusEnum::PENDING || 
                   ($task->status === TaskStatusEnum::IN_PROGRESS && $task->cs_user_id === $user->id);
        });

        if ($tasks->isEmpty()) {
            $firstActiveTask = $activeShiftTasks->first();
            
            if ($firstActiveTask->status === TaskStatusEnum::COMPLETED) {
                return $this->error('Tugas kebersihan ruangan ini pada shift sekarang sudah selesai dikerjakan.', [], 400);
            }
            
            if ($firstActiveTask->status === TaskStatusEnum::WAITING_VERIFICATION) {
                return $this->error('Laporan tugas kebersihan ruangan ini sedang menunggu verifikasi dari PIC.', [], 400);
            }
            
            if ($firstActiveTask->status === TaskStatusEnum::IN_PROGRESS) {
                return $this->error('Tugas ruangan ini sedang dikerjakan oleh CS lain saat ini.', [], 400);
            }
            
            return $this->error('Tidak ada tugas aktif atau tertunda untuk shift ruangan ini pada jam sekarang.', [], 404);
        }

        return DB::transaction(function () use ($tasks, $room, $user) {
            // Update all pending tasks in this shift to in_progress and assign to the scanning CS
            foreach ($tasks as $task) {
                $oldTask = $task->toArray();
                $updateData = [];
                
                if ($task->status === TaskStatusEnum::PENDING) {
                    $updateData['status'] = TaskStatusEnum::IN_PROGRESS;
                }
                
                if ($task->cs_user_id !== $user->id) {
                    $updateData['cs_user_id'] = $user->id;
                }
                
                if (!empty($updateData)) {
                    $task->update($updateData);
                    AuditLogService::log('START_TASK', 'tasks', $task->id, $oldTask, $task->toArray());
                }
            }

            // Ambil checklist items yang dijadwalkan untuk tasks tersebut
            $checklistItems = ChecklistItem::whereIn('id', function($q) use ($tasks) {
                $q->select('checklist_item_id')
                  ->from('schedules')
                  ->whereIn('id', $tasks->pluck('schedule_id'));
            })->where('is_active', true)->get();

            return $this->success([
                'task' => new \App\Http\Resources\TaskResource($tasks->first()), // representative task
                'checklist_items' => $checklistItems->map(fn($item) => [
                    'id' => $item->id,
                    'nama_item' => $item->nama_item,
                    'kategori' => $item->kategori,
                ]),
            ], 'Scan QR Code berhasil. Silakan mulai pengerjaan tugas.');
        });
    }

    /**
     * Submit laporan pengerjaan checklist kebersihan
     * POST /submissions
     * 
     * @OA\Post(
     *     path="/api/v1/submissions",
     *     summary="Submit laporan kebersihan",
     *     description="Mengirimkan hasil checklist kebersihan",
     *     tags={"Submissions"},
     *     @OA\Response(
     *         response=200,
     *         description="Sukses"
     *     )
     * )
     */
    public function store(Request $request)
    {
        $request->validate([
            'task_id' => ['required', 'uuid', 'exists:tasks,id'],
            'catatan_cs' => ['nullable', 'string'],
            'results' => ['required', 'array', 'min:1'],
            'results.*.checklist_item_id' => ['required', 'uuid', 'exists:checklist_items,id'],
            'results.*.is_done' => ['required', 'boolean'],
            'results.*.catatan' => ['nullable', 'string'],
            'foto_after_1' => ['required', 'image', 'max:5120'],
            'foto_after_2' => ['required', 'image', 'max:5120'],
            'foto_after_3' => ['required', 'image', 'max:5120'],
            'foto_after_4' => ['required', 'image', 'max:5120'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'gps_accuracy' => ['nullable', 'numeric'],
            'gps_captured_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $representativeTask = Task::findOrFail($request->task_id);

        if ($representativeTask->cs_user_id !== $user->id) {
            return $this->error('Akses ditolak. Tugas ini tidak ditugaskan kepada Anda.', [], 403);
        }

        // Ambil semua tasks in_progress untuk CS, ruangan, shift, dan tanggal yang sama
        $tasks = Task::where('room_id', $representativeTask->room_id)
            ->where('cs_user_id', $user->id)
            ->where('shift_id', $representativeTask->shift_id)
            ->where('tanggal_task', $representativeTask->tanggal_task)
            ->where('status', TaskStatusEnum::IN_PROGRESS)
            ->get();

        if ($tasks->isEmpty()) {
            return $this->error('Tugas harus berstatus "in_progress" sebelum dapat diserahkan.', [], 400);
        }

        // Cek jika submission sudah pernah ada untuk representative task (untuk resubmit lewat endpoint resubmit)
        $existingSubmission = ChecklistSubmission::where('task_id', $representativeTask->id)->first();
        if ($existingSubmission) {
            return $this->error('Laporan untuk tugas ini sudah pernah diserahkan. Gunakan endpoint resubmit jika ingin memperbarui.', [], 400);
        }

        // Membaca file gambar sebagai biner
        $fotoAfter1 = file_get_contents($request->file('foto_after_1')->getRealPath());
        $fotoAfter1Mime = $request->file('foto_after_1')->getMimeType();
        $fotoAfter2 = file_get_contents($request->file('foto_after_2')->getRealPath());
        $fotoAfter2Mime = $request->file('foto_after_2')->getMimeType();
        $fotoAfter3 = file_get_contents($request->file('foto_after_3')->getRealPath());
        $fotoAfter3Mime = $request->file('foto_after_3')->getMimeType();
        $fotoAfter4 = file_get_contents($request->file('foto_after_4')->getRealPath());
        $fotoAfter4Mime = $request->file('foto_after_4')->getMimeType();

        // Validasi Geofence GPS
        $geofenceError = '';
        if (!$this->validateGeofence($representativeTask->room, $request->latitude, $request->longitude, $geofenceError)) {
            return $this->error($geofenceError, [], 403);
        }

        return DB::transaction(function () use (
            $request,
            $user,
            $representativeTask,
            $tasks,
            $fotoAfter1,
            $fotoAfter1Mime,
            $fotoAfter2,
            $fotoAfter2Mime,
            $fotoAfter3,
            $fotoAfter3Mime,
            $fotoAfter4,
            $fotoAfter4Mime
        ) {
            // 1. Buat ChecklistSubmission
            $submission = ChecklistSubmission::create([
                'id' => (string) Str::uuid(),
                'task_id' => $representativeTask->id,
                'cs_user_id' => $user->id,
                'submitted_at' => now(),
                'resubmit_count' => 0,
                'scan_token_used' => $representativeTask->room->qr_code_token ?? '',
                'catatan_cs' => $request->catatan_cs,
                'status' => SubmissionStatusEnum::SUBMITTED,
                'foto_after_1' => $fotoAfter1,
                'foto_after_1_mime' => $fotoAfter1Mime,
                'foto_after_2' => $fotoAfter2,
                'foto_after_2_mime' => $fotoAfter2Mime,
                'foto_after_3' => $fotoAfter3,
                'foto_after_3_mime' => $fotoAfter3Mime,
                'foto_after_4' => $fotoAfter4,
                'foto_after_4_mime' => $fotoAfter4Mime,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'gps_accuracy' => $request->gps_accuracy,
                'gps_captured_at' => $request->gps_captured_at ? \Illuminate\Support\Carbon::parse($request->gps_captured_at) : null,
            ]);

            // 2. Simpan ChecklistResults
            foreach ($request->results as $res) {
                ChecklistResult::create([
                    'id' => (string) Str::uuid(),
                    'submission_id' => $submission->id,
                    'checklist_item_id' => $res['checklist_item_id'],
                    'is_done' => filter_var($res['is_done'], FILTER_VALIDATE_BOOLEAN),
                    'catatan' => $res['catatan'] ?? null,
                ]);
            }

            // 3. Update status ALL related tasks to waiting_verification
            foreach ($tasks as $task) {
                $oldTask = $task->toArray();
                $task->update([
                    'status' => TaskStatusEnum::WAITING_VERIFICATION,
                ]);
                AuditLogService::log('UPDATE_TASK_STATUS_TO_WAITING_VERIFICATION', 'tasks', $task->id, $oldTask, $task->toArray());
            }

            // 4. Kirim notifikasi ke PIC aktif ruangan tersebut
            $pic = $representativeTask->room?->pic;
            if ($pic) {
                NotificationService::send(
                    $pic->id,
                    'CHECKLIST_SUBMITTED',
                    "Laporan Baru: " . ($representativeTask->room?->nama_ruangan ?? 'Ruangan'),
                    "CS {$user->full_name} telah menyerahkan laporan kebersihan untuk ruang " . ($representativeTask->room?->nama_ruangan ?? ''),
                    [
                        'submission_id' => $submission->id,
                        'room_name' => $representativeTask->room?->nama_ruangan,
                        'cs_name' => $user->full_name,
                    ],
                    'both'
                );
            }

            AuditLogService::log('SUBMIT_CHECKLIST_REPORT', 'checklist_submissions', $submission->id, null, $submission->load('results')->toArray());

            return $this->success(
                new ChecklistSubmissionResource($submission->load('results.checklistItem')),
                'Laporan kebersihan berhasil diserahkan. Menunggu verifikasi PIC.',
                201
            );
        });
    }

    /**
     * Resubmit setelah ditolak
     * POST /submissions/{id}/resubmit
     */
    public function resubmit(Request $request, $id)
    {
        $submission = ChecklistSubmission::findOrFail($id);

        Gate::authorize('update', $submission);

        $request->validate([
            'catatan_cs' => ['nullable', 'string'],
            'results' => ['required', 'array', 'min:1'],
            'results.*.checklist_item_id' => ['required', 'uuid', 'exists:checklist_items,id'],
            'results.*.is_done' => ['required', 'boolean'],
            'results.*.catatan' => ['nullable', 'string'],
            'foto_after_1' => ['required', 'image', 'max:5120'],
            'foto_after_2' => ['required', 'image', 'max:5120'],
            'foto_after_3' => ['required', 'image', 'max:5120'],
            'foto_after_4' => ['required', 'image', 'max:5120'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'gps_accuracy' => ['nullable', 'numeric'],
            'gps_captured_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $representativeTask = $submission->task;

        if ($submission->status !== SubmissionStatusEnum::REJECTED) {
            return $this->error('Laporan hanya bisa diserahkan kembali jika sebelumnya ditolak.', [], 400);
        }

        // Ambil semua tasks (yang berstatus rejected) untuk CS, ruangan, shift, dan tanggal yang sama
        $tasks = Task::where('room_id', $representativeTask->room_id)
            ->where('cs_user_id', $user->id)
            ->where('shift_id', $representativeTask->shift_id)
            ->where('tanggal_task', $representativeTask->tanggal_task)
            ->where('status', TaskStatusEnum::REJECTED)
            ->get();

        if ($tasks->isEmpty()) {
            return $this->error('Tidak ada tugas berstatus "rejected" untuk laporan ini.', [], 400);
        }

        // Membaca file gambar sebagai biner
        $fotoAfter1 = file_get_contents($request->file('foto_after_1')->getRealPath());
        $fotoAfter1Mime = $request->file('foto_after_1')->getMimeType();
        $fotoAfter2 = file_get_contents($request->file('foto_after_2')->getRealPath());
        $fotoAfter2Mime = $request->file('foto_after_2')->getMimeType();
        $fotoAfter3 = file_get_contents($request->file('foto_after_3')->getRealPath());
        $fotoAfter3Mime = $request->file('foto_after_3')->getMimeType();
        $fotoAfter4 = file_get_contents($request->file('foto_after_4')->getRealPath());
        $fotoAfter4Mime = $request->file('foto_after_4')->getMimeType();

        // Validasi Geofence GPS
        $geofenceError = '';
        if (!$this->validateGeofence($representativeTask->room, $request->latitude, $request->longitude, $geofenceError)) {
            return $this->error($geofenceError, [], 403);
        }

        return DB::transaction(function () use (
            $request,
            $user,
            $submission,
            $tasks,
            $fotoAfter1,
            $fotoAfter1Mime,
            $fotoAfter2,
            $fotoAfter2Mime,
            $fotoAfter3,
            $fotoAfter3Mime,
            $fotoAfter4,
            $fotoAfter4Mime
        ) {
            $oldSubmission = $submission->toArray();

            // 1. Update ChecklistSubmission
            $submission->update([
                'submitted_at' => now(),
                'resubmit_count' => $submission->resubmit_count + 1,
                'catatan_cs' => $request->catatan_cs,
                'status' => SubmissionStatusEnum::SUBMITTED,
                'foto_after_1' => $fotoAfter1,
                'foto_after_1_mime' => $fotoAfter1Mime,
                'foto_after_2' => $fotoAfter2,
                'foto_after_2_mime' => $fotoAfter2Mime,
                'foto_after_3' => $fotoAfter3,
                'foto_after_3_mime' => $fotoAfter3Mime,
                'foto_after_4' => $fotoAfter4,
                'foto_after_4_mime' => $fotoAfter4Mime,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'gps_accuracy' => $request->gps_accuracy,
                'gps_captured_at' => $request->gps_captured_at ? \Illuminate\Support\Carbon::parse($request->gps_captured_at) : null,
            ]);

            // 2. Hapus results lama dan buat baru
            ChecklistResult::where('submission_id', $submission->id)->delete();
            foreach ($request->results as $res) {
                ChecklistResult::create([
                    'id' => (string) Str::uuid(),
                    'submission_id' => $submission->id,
                    'checklist_item_id' => $res['checklist_item_id'],
                    'is_done' => filter_var($res['is_done'], FILTER_VALIDATE_BOOLEAN),
                    'catatan' => $res['catatan'] ?? null,
                ]);
            }

            // 3. Update status related tasks kembali ke waiting_verification
            foreach ($tasks as $task) {
                $oldTask = $task->toArray();
                $task->update([
                    'status' => TaskStatusEnum::WAITING_VERIFICATION,
                ]);
                AuditLogService::log('UPDATE_TASK_STATUS_TO_WAITING_VERIFICATION', 'tasks', $task->id, $oldTask, $task->toArray());
            }

            // 4. Kirim notifikasi ke PIC aktif ruangan tersebut
            $pic = $submission->task?->room?->pic;
            if ($pic) {
                NotificationService::send(
                    $pic->id,
                    'CHECKLIST_SUBMITTED',
                    "Laporan Diserahkan Kembali: " . ($submission->task?->room?->nama_ruangan ?? 'Ruangan'),
                    "CS {$user->full_name} telah menyerahkan kembali laporan kebersihan untuk ruang " . ($submission->task?->room?->nama_ruangan ?? ''),
                    [
                        'submission_id' => $submission->id,
                        'room_name' => $submission->task?->room?->nama_ruangan,
                        'cs_name' => $user->full_name,
                    ],
                    'both'
                );
            }

            AuditLogService::log('RESUBMIT_CHECKLIST_REPORT', 'checklist_submissions', $submission->id, $oldSubmission, $submission->load('results')->toArray());

            return $this->success(
                new ChecklistSubmissionResource($submission->load('results.checklistItem')),
                'Laporan kebersihan berhasil diserahkan kembali. Menunggu verifikasi PIC.'
            );
        });
    }

    /**
     * Tampilkan detail submission
     */
    public function show($id)
    {
        $submission = ChecklistSubmission::with(['task.room', 'cs', 'results.checklistItem'])->findOrFail($id);

        Gate::authorize('view', $submission);

        return $this->success(new ChecklistSubmissionResource($submission), 'Detail laporan berhasil diambil.');
    }

    /**
     * Stream foto before dari database
     */
    public function streamFotoBefore($id)
    {
        $submission = ChecklistSubmission::findOrFail($id);

        Gate::authorize('view', $submission);

        if (!$submission->foto_before) {
            return $this->error('Foto sebelum pengerjaan tidak ditemukan.', [], 404);
        }

        $mimeType = $submission->foto_before_mime ?? 'image/jpeg';

        return response($submission->foto_before, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="before_' . $submission->id . '.jpg"');
    }

    /**
     * Stream foto after dari database
     */
    public function streamFotoAfter($id)
    {
        $submission = ChecklistSubmission::findOrFail($id);

        Gate::authorize('view', $submission);

        if (!$submission->foto_after) {
            return $this->error('Foto setelah pengerjaan tidak ditemukan.', [], 404);
        }

        $mimeType = $submission->foto_after_mime ?? 'image/jpeg';

        return response($submission->foto_after, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="after_' . $submission->id . '.jpg"');
    }

    public function streamFotoAfter3($id)
    {
        $submission = ChecklistSubmission::findOrFail($id);
        Gate::authorize('view', $submission);
        if (!$submission->foto_after_3) {
            return $this->error('Foto after 3 tidak ditemukan.', [], 404);
        }
        $mimeType = $submission->foto_after_3_mime ?? 'image/jpeg';
        return response($submission->foto_after_3, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="after_3_' . $submission->id . '.jpg"');
    }

    public function streamFotoAfter4($id)
    {
        $submission = ChecklistSubmission::findOrFail($id);
        Gate::authorize('view', $submission);
        if (!$submission->foto_after_4) {
            return $this->error('Foto after 4 tidak ditemukan.', [], 404);
        }
        $mimeType = $submission->foto_after_4_mime ?? 'image/jpeg';
        return response($submission->foto_after_4, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="after_4_' . $submission->id . '.jpg"');
    }

    public function streamFotoAfter1($id)
    {
        $submission = ChecklistSubmission::findOrFail($id);
        Gate::authorize('view', $submission);
        if (!$submission->foto_after_1) {
            return $this->error('Foto after 1 tidak ditemukan.', [], 404);
        }
        $mimeType = $submission->foto_after_1_mime ?? 'image/jpeg';
        return response($submission->foto_after_1, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="after_1_' . $submission->id . '.jpg"');
    }

    public function streamFotoAfter2($id)
    {
        $submission = ChecklistSubmission::findOrFail($id);
        Gate::authorize('view', $submission);
        if (!$submission->foto_after_2) {
            return $this->error('Foto after 2 tidak ditemukan.', [], 404);
        }
        $mimeType = $submission->foto_after_2_mime ?? 'image/jpeg';
        return response($submission->foto_after_2, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="after_2_' . $submission->id . '.jpg"');
    }

    /**
     * Menghitung jarak antara dua koordinat menggunakan rumus Haversine (dalam meter).
     */
    private function calculateDistance($lat1, $lon1, $lat2, $lon2)
    {
        $earthRadius = 6371000; // Radius bumi dalam meter

        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Memvalidasi apakah posisi CS berada di dalam batas radius gedung.
     */
    private function validateGeofence($room, $lat, $lon, &$errorMessage)
    {
        $enabled = \App\Helpers\SettingHelper::get('geofence_verification_enabled', false);
        if (!$enabled) {
            return true;
        }

        $building = $room->building;
        if (!$building || $building->latitude === null || $building->longitude === null) {
            // Jika koordinat gedung belum diatur, lewati pengecekan
            return true;
        }

        if ($lat === null || $lon === null) {
            $errorMessage = 'Akses ditolak: Verifikasi GPS diaktifkan. Harap aktifkan GPS perangkat Anda.';
            return false;
        }

        $distance = $this->calculateDistance($lat, $lon, $building->latitude, $building->longitude);
        $allowedDistance = \App\Helpers\SettingHelper::get('geofence_allowed_distance_meters', 50);

        if ($distance > $allowedDistance) {
            $errorMessage = sprintf(
                'Akses ditolak: Anda terdeteksi berada di luar radius gedung %s. Jarak Anda: %d meter (Batas toleransi: %d meter).',
                $building->nama_gedung,
                round($distance),
                $allowedDistance
            );
            return false;
        }

        return true;
    }
}
