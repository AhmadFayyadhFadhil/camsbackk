<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ChecklistSubmissionResource;
use App\Models\ChecklistSubmission;
use App\Models\Verification;
use App\Models\Task;
use App\Enums\SubmissionStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Traits\ApiResponse;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    use ApiResponse;

    /**
     * Tampilkan list laporan kebersihan yang menunggu persetujuan (pending).
     * Khusus PIC, hanya menampilkan area kelolaannya sendiri.
     */
    public function pending(Request $request)
    {
        $user = $request->user();

        // CS tidak diijinkan melihat pending verifications
        if ($user->hasRole(\App\Enums\RoleEnum::CS)) {
            return $this->error('Akses ditolak.', [], 403);
        }

        $query = ChecklistSubmission::query()
            ->with(['task.room.building', 'cs', 'results.checklistItem'])
            ->where('status', SubmissionStatusEnum::SUBMITTED);

        // Jika PIC, filter berdasarkan area tanggung jawab PIC tersebut
        if ($user->hasRole(\App\Enums\RoleEnum::PIC)) {
            $query->whereHas('task.room', function ($q) use ($user) {
                $q->where(function ($subQuery) use ($user) {
                    $subQuery->where('pic_user_id', $user->id)
                             ->orWhereIn('id', function ($sub) use ($user) {
                                 $sub->select('room_id')
                                     ->from('room_pic_histories')
                                     ->where('user_id', $user->id)
                                     ->where(function ($dateQuery) {
                                         $dateQuery->whereNull('tanggal_selesai')
                                                   ->orWhere('tanggal_selesai', '>=', today()->toDateString());
                                     });
                             });
                });
            });
        }

        $perPage = $request->get('per_page', 20);
        $submissions = $query->paginate($perPage);

        return $this->paginated(
            ChecklistSubmissionResource::collection($submissions),
            'Daftar laporan pending berhasil diambil.'
        );
    }

    /**
     * Setujui (Approve) laporan kebersihan.
     */
    public function approve(Request $request, $submissionId)
    {
        $submission = ChecklistSubmission::findOrFail($submissionId);

        // Validasi hak akses PIC / Supervisor / Admin
        Gate::authorize('create', [Verification::class, $submission]);

        if ($submission->status !== SubmissionStatusEnum::SUBMITTED) {
            return $this->error('Laporan ini sudah diproses sebelumnya.', [], 400);
        }

        return DB::transaction(function () use ($request, $submission) {
            $user = $request->user();
            $roleVerifier = $user->hasRole(\App\Enums\RoleEnum::PIC) ? 'pic' : 'supervisor';

            // 1. Simpan Verifikasi
            $verification = Verification::create([
                'id' => (string) Str::uuid(),
                'submission_id' => $submission->id,
                'verified_by' => $user->id,
                'role_verifier' => $roleVerifier,
                'status' => 'approved',
                'catatan_perbaikan' => $request->notes ?? 'Laporan disetujui.',
                'verified_at' => now(),
            ]);

            // 2. Update status submission menjadi approved
            $oldSubData = $submission->toArray();
            $submission->update([
                'status' => SubmissionStatusEnum::APPROVED,
            ]);

            // 3. Update status dari seluruh task yang berkaitan menjadi completed
            $representativeTask = $submission->task;
            $tasks = Task::where('room_id', $representativeTask->room_id)
                ->where('cs_user_id', $representativeTask->cs_user_id)
                ->where('shift_id', $representativeTask->shift_id)
                ->where('tanggal_task', $representativeTask->tanggal_task)
                ->get();

            foreach ($tasks as $task) {
                $oldTaskData = $task->toArray();
                $task->update([
                    'status' => TaskStatusEnum::COMPLETED,
                ]);

                AuditLogService::log(
                    'UPDATE_TASK_STATUS_TO_COMPLETED',
                    'tasks',
                    $task->id,
                    $oldTaskData,
                    $task->toArray()
                );
            }

            // Kirim notifikasi ke CS
            \App\Services\NotificationService::send(
                $submission->cs_user_id,
                'checklist_approved',
                "Laporan Disetujui: " . ($representativeTask->room?->nama_ruangan ?? 'Ruangan'),
                "Laporan kebersihan Anda untuk ruang " . ($representativeTask->room?->nama_ruangan ?? '') . " telah disetujui.",
                [
                    'submission_id' => $submission->id,
                    'room_name' => $representativeTask->room?->nama_ruangan,
                    'verified_by' => $user->full_name,
                    'notes' => $verification->catatan_perbaikan,
                ],
                'both'
            );

            // 4. Log Audit Trail untuk Verifikasi dan Submission
            AuditLogService::log(
                'VERIFY_SUBMISSION_APPROVE',
                'verifications',
                $verification->id,
                null,
                $verification->toArray()
            );

            AuditLogService::log(
                'UPDATE_SUBMISSION_STATUS_TO_APPROVED',
                'checklist_submissions',
                $submission->id,
                $oldSubData,
                $submission->toArray()
            );

            return $this->success(
                new ChecklistSubmissionResource($submission->load('results.checklistItem')),
                'Laporan kebersihan berhasil disetujui.'
            );
        });
    }

    /**
     * Tolak (Reject) laporan kebersihan dengan catatan perbaikan wajib.
     */
    public function reject(Request $request, $submissionId)
    {
        $request->validate([
            'catatan_perbaikan' => ['required', 'string'],
        ]);

        $submission = ChecklistSubmission::findOrFail($submissionId);

        // Validasi hak akses PIC / Supervisor / Admin
        Gate::authorize('create', [Verification::class, $submission]);

        if ($submission->status !== SubmissionStatusEnum::SUBMITTED) {
            return $this->error('Laporan ini sudah diproses sebelumnya.', [], 400);
        }

        return DB::transaction(function () use ($request, $submission) {
            $user = $request->user();
            $roleVerifier = $user->hasRole(\App\Enums\RoleEnum::PIC) ? 'pic' : 'supervisor';

            // 1. Simpan Verifikasi
            $verification = Verification::create([
                'id' => (string) Str::uuid(),
                'submission_id' => $submission->id,
                'verified_by' => $user->id,
                'role_verifier' => $roleVerifier,
                'status' => 'rejected',
                'catatan_perbaikan' => $request->catatan_perbaikan,
                'verified_at' => now(),
            ]);

            // 2. Update status submission menjadi rejected
            $oldSubData = $submission->toArray();
            $submission->update([
                'status' => SubmissionStatusEnum::REJECTED,
            ]);

            // 3. Update status dari seluruh task yang berkaitan menjadi rejected agar CS bisa resubmit
            $representativeTask = $submission->task;
            $tasks = Task::where('room_id', $representativeTask->room_id)
                ->where('cs_user_id', $representativeTask->cs_user_id)
                ->where('shift_id', $representativeTask->shift_id)
                ->where('tanggal_task', $representativeTask->tanggal_task)
                ->get();

            foreach ($tasks as $task) {
                $oldTaskData = $task->toArray();
                $task->update([
                    'status' => TaskStatusEnum::REJECTED,
                ]);

                AuditLogService::log(
                    'UPDATE_TASK_STATUS_TO_REJECTED',
                    'tasks',
                    $task->id,
                    $oldTaskData,
                    $task->toArray()
                );
            }

            // Kirim notifikasi penolakan ke CS
            \App\Services\NotificationService::send(
                $submission->cs_user_id,
                'checklist_rejected',
                "Laporan Ditolak: " . ($representativeTask->room?->nama_ruangan ?? 'Ruangan'),
                "Laporan kebersihan Anda ditolak. Catatan: " . $request->catatan_perbaikan,
                [
                    'submission_id' => $submission->id,
                    'room_name' => $representativeTask->room?->nama_ruangan,
                    'verified_by' => $user->full_name,
                    'notes' => $request->catatan_perbaikan,
                ],
                'both'
            );

            // 4. Log Audit Trail untuk Verifikasi dan Submission
            AuditLogService::log(
                'VERIFY_SUBMISSION_REJECT',
                'verifications',
                $verification->id,
                null,
                $verification->toArray()
            );

            AuditLogService::log(
                'UPDATE_SUBMISSION_STATUS_TO_REJECTED',
                'checklist_submissions',
                $submission->id,
                $oldSubData,
                $submission->toArray()
            );

            return $this->success(
                new ChecklistSubmissionResource($submission->load('results.checklistItem')),
                'Laporan kebersihan ditolak. Catatan perbaikan telah dikirim ke CS.'
            );
        });
    }
}
