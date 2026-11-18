<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoleEnum;
use App\Enums\SubmissionStatusEnum;
use App\Enums\TaskStatusEnum;
use App\Enums\VerificationStageEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\ChecklistSubmissionResource;
use App\Models\ChecklistSubmission;
use App\Models\Task;
use App\Models\User;
use App\Models\Verification;
use App\Models\VerificationSlaRating;
use App\Models\VerifierDutyLog;
use App\Services\AuditLogService;
use App\Services\NotificationService;
use App\Services\VerificationWorkflowService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Workflow Checklist Harian:
 *   CS submit -> Supervisor Verifikator (Pak Kusnan) -> Supervisor tertinggi (Pak Winaryo) approval akhir -> Selesai
 * PIC Ruangan tidak terlibat. Pak Denny (operational) hanya dapat melihat antrean.
 */
class VerificationController extends Controller
{
    use ApiResponse;

    public function __construct(private VerificationWorkflowService $workflow)
    {
    }

    /**
     * GET /verifications/pending?scope=mine|all
     * mine = laporan yang dapat saya tindak lanjuti (Pak Kusnan / Pak Winaryo)
     * all  = seluruh antrean (read-only untuk Admin / Supervisor lain)
     */
    public function pending(Request $request)
    {
        $user = $request->user();

        if (!$this->workflow->canViewQueue($user)) {
            return $this->error('Akses ditolak.', [], 403);
        }

        $defaultScope = ($user->isVerifierSupervisor() || $user->isHeadSupervisor()) ? 'mine' : 'all';
        $scope = $request->get('scope', $defaultScope) === 'all' ? 'all' : 'mine';

        $query = $this->workflow->pendingQuery($user, $scope)
            ->with([
                'task.room.building',
                'cs',
                'results.checklistItem',
                'latestVerification',
                'verifier:id,full_name',
                'verifiedByUser:id,full_name',
                'finalApprover:id,full_name',
                'redirects.fromUser:id,full_name',
                'redirects.toUser:id,full_name',
                'redirects.actor:id,full_name',
            ])
            ->orderBy('verification_deadline_at')
            ->orderBy('submitted_at');

        $perPage = (int) $request->get('per_page', 20);
        $submissions = $query->paginate($perPage);

        return $this->paginated(
            ChecklistSubmissionResource::collection($submissions),
            'Daftar laporan pending berhasil diambil.'
        );
    }

    /**
     * GET /verifications/workflow-summary
     * Ringkasan SLA, antrean, dan status ketersediaan Pak Kusnan.
     */
    public function workflowSummary(Request $request)
    {
        $user = $request->user();
        if (!$this->workflow->canViewQueue($user)) {
            return $this->error('Akses ditolak.', [], 403);
        }

        return $this->success($this->workflow->summary($user), 'Ringkasan workflow verifikasi berhasil diambil.');
    }

    /**
     * PATCH /verifications/verifier-duty
     * Hanya Pak Winaryo (Supervisor tertinggi) atau Admin yang boleh mengatur status Pak Kusnan.
     */
    public function setVerifierDuty(Request $request)
    {
        $actor = $request->user();

        if (!$this->workflow->canManageVerifierDuty($actor)) {
            return $this->error('Hanya Supervisor tertinggi atau Admin yang dapat mengatur status ketersediaan Supervisor Verifikator.', [], 403);
        }

        $data = $request->validate([
            'is_on_duty' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if:is_on_duty,false,0'],
        ], [
            'reason.required_if' => 'Alasan wajib diisi saat Supervisor Verifikator diset tidak bertugas.',
        ]);

        $verifier = $this->workflow->verifier();
        if (!$verifier) {
            return $this->error('Supervisor Verifikator belum dikonfigurasi. Atur level hierarki pada akun Pak Kusnan atau jalankan ChecklistVerificationHierarchySeeder.', [], 404);
        }

        $result = $this->workflow->setVerifierDuty(
            $verifier,
            (bool) $data['is_on_duty'],
            $data['reason'] ?? null,
            $actor
        );

        $message = $result['is_on_duty']
            ? 'Supervisor Verifikator diset bertugas.'
            : "Supervisor Verifikator diset tidak bertugas. {$result['redirected']} laporan antre dialihkan ke Supervisor tertinggi.";

        return $this->success($this->workflow->summary($actor), $message);
    }

    /**
     * GET /verifications/verifier-duty-logs
     */
    public function verifierDutyLogs(Request $request)
    {
        if (!$this->workflow->canViewQueue($request->user())) {
            return $this->error('Akses ditolak.', [], 403);
        }

        $logs = VerifierDutyLog::query()
            ->with(['user:id,full_name', 'changer:id,full_name'])
            ->latest('changed_at')
            ->limit(50)
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'verifier_name' => $l->user?->full_name,
                'is_on_duty' => (bool) $l->is_on_duty,
                'reason' => $l->reason,
                'changed_by' => $l->changer?->full_name,
                'changed_at' => $l->changed_at?->toIso8601String(),
            ]);

        return $this->success($logs, 'Riwayat status ketersediaan berhasil diambil.');
    }

    /**
     * POST /verifications/{submissionId}/approve
     * - Tahap verifikasi (Pak Kusnan, atau Pak Winaryo pada laporan yang dialihkan): wajib foto inspeksi,
     *   laporan diteruskan ke approval akhir.
     * - Tahap approval akhir (Pak Winaryo): laporan dinyatakan selesai.
     */
    public function approve(Request $request, $submissionId)
    {
        $submission = ChecklistSubmission::with(['task.room.building', 'cs'])->findOrFail($submissionId);
        $user = $request->user();

        $action = $this->workflow->actionFor($user, $submission);
        if ($action === null) {
            return $this->denyAction($submission);
        }

        $isVerifyStage = $action === VerificationWorkflowService::ACTION_VERIFY;

        $rules = [
            'notes' => ['nullable', 'string'],
            'sla_ratings' => ['nullable', 'array'],
            'sla_ratings.*.sla_parameter_id' => ['required_with:sla_ratings', 'uuid', 'exists:sla_parameters,id'],
            'sla_ratings.*.nilai' => ['required_with:sla_ratings', 'string'],
        ];
        if ($isVerifyStage) {
            $rules += [
                'room_qr_code' => ['nullable', 'string'],
                'foto_inspeksi' => ['required', 'image', 'max:10240'],
                'latitude' => ['nullable', 'numeric'],
                'longitude' => ['nullable', 'numeric'],
                'qr_scanned_at' => ['nullable', 'date'],
                'is_onsite_verified' => ['nullable', 'boolean'],
            ];
        }
        $request->validate($rules, [
            'foto_inspeksi.required' => 'Foto bukti inspeksi fisik ruangan wajib dilampirkan sebelum menyetujui laporan.',
        ]);

        // QR & GPS hanya relevan pada inspeksi fisik (tahap verifikasi)
        if ($isVerifyStage) {
            $expectedRoom = $submission->task?->room;

            if ($request->filled('room_qr_code') && $expectedRoom) {
                $scannedQr = trim($request->room_qr_code);
                $parsedQr = json_decode($scannedQr, true);
                $scannedRoomId = is_array($parsedQr) ? ($parsedQr['room_id'] ?? null) : null;
                $scannedToken = is_array($parsedQr) ? ($parsedQr['token'] ?? null) : null;

                $isValid = (
                    $scannedQr === $expectedRoom->kode_ruangan ||
                    $scannedQr === $expectedRoom->id ||
                    $scannedQr === $expectedRoom->qr_code_token ||
                    ($scannedRoomId && ($scannedRoomId === $expectedRoom->id || $scannedRoomId === $expectedRoom->kode_ruangan)) ||
                    ($scannedToken && $scannedToken === $expectedRoom->qr_code_token)
                );

                if (!$isValid) {
                    return $this->error("QR Code tidak cocok dengan ruangan laporan ({$expectedRoom->nama_ruangan}). Pastikan Anda berada di ruangan yang benar.", [], 422);
                }
            }

            if ($expectedRoom && $request->filled('latitude') && $request->filled('longitude')) {
                $geofence = $expectedRoom->getEffectiveGeofence();
                if ($geofence['type'] !== 'none') {
                    $distance = \App\Helpers\GeoHelper::haversineDistance(
                        (float) $request->latitude,
                        (float) $request->longitude,
                        $geofence['latitude'],
                        $geofence['longitude']
                    );
                    $maxRadius = $geofence['radius_meter'];
                    if ($distance > $maxRadius) {
                        $targetLabel = $geofence['type'] === 'room' ? "ruangan {$geofence['target_name']}" : "kawasan {$geofence['target_name']}";
                        return $this->error("Posisi GPS Anda terdeteksi di luar area {$targetLabel} (Jarak terdeteksi: " . round($distance) . " meter, batas radius: {$maxRadius} meter). Pastikan Anda berada di lokasi.", [
                            'distance' => round($distance),
                            'max_radius' => $maxRadius,
                            'geofence_type' => $geofence['type'],
                        ], 422);
                    }
                }
            }
        }

        return DB::transaction(function () use ($request, $submissionId, $user) {
            // Kunci baris agar aksi pengguna dan scheduler pengalihan tidak saling menimpa
            $submission = ChecklistSubmission::query()->whereKey($submissionId)->lockForUpdate()->first();
            $action = $submission ? $this->workflow->actionFor($user, $submission) : null;
            if ($action === null) {
                return $this->denyAction($submission);
            }

            $isVerifyStage = $action === VerificationWorkflowService::ACTION_VERIFY;
            // Pak Winaryo yang menangani laporan dialihkan: verifikasi dan approval akhir selesai dalam satu aksi
            $completesNow = !$isVerifyStage || $user->isHeadSupervisor();
            $now = now();

            $fotoInspeksiPath = null;
            if ($isVerifyStage && $request->hasFile('foto_inspeksi')) {
                $fotoInspeksiPath = $request->file('foto_inspeksi')->store('inspections', 'public');
            }

            $defaultNote = $isVerifyStage
                ? 'Laporan disetujui setelah cek fisik di lokasi.'
                : 'Laporan disetujui (approval akhir).';

            $createVerification = function (string $stage, ?string $photoPath) use ($request, $submission, $user, $now, $defaultNote, $isVerifyStage) {
                return Verification::create([
                    'id' => (string) Str::uuid(),
                    'submission_id' => $submission->id,
                    'verified_by' => $user->id,
                    'role_verifier' => 'supervisor',
                    'stage' => $stage,
                    'status' => 'approved',
                    'catatan_perbaikan' => $request->notes ?? $defaultNote,
                    'foto_inspeksi_path' => $photoPath,
                    'qr_scanned_at' => $isVerifyStage && $stage === 'verification'
                        ? ($request->filled('qr_scanned_at') ? Carbon::parse($request->qr_scanned_at) : $now)
                        : null,
                    'latitude' => $isVerifyStage && $stage === 'verification' ? $request->latitude : null,
                    'longitude' => $isVerifyStage && $stage === 'verification' ? $request->longitude : null,
                    'is_onsite_verified' => $isVerifyStage && $stage === 'verification'
                        ? $request->boolean('is_onsite_verified', true)
                        : false,
                    'verified_at' => $now,
                ]);
            };

            $oldSubData = $submission->toArray();
            $verification = null;

            if ($isVerifyStage) {
                $verification = $createVerification('verification', $fotoInspeksiPath);
                $this->storeSlaRatings($request, $verification);

                $submission->update([
                    'verification_stage' => VerificationStageEnum::PENDING_FINAL_APPROVAL->value,
                    'verified_at' => $now,
                    'verified_by' => $user->id,
                ]);

                AuditLogService::log('VERIFY_SUBMISSION_APPROVE', 'verifications', $verification->id, null, $verification->toArray());
            }

            if ($completesNow) {
                $finalVerification = $createVerification('final_approval', null);
                if (!$isVerifyStage) {
                    $this->storeSlaRatings($request, $finalVerification);
                }

                $submission->update([
                    'status' => SubmissionStatusEnum::APPROVED,
                    'verification_stage' => VerificationStageEnum::COMPLETED->value,
                    'final_approved_at' => $now,
                    'final_approved_by' => $user->id,
                ]);

                $this->updateRelatedTasks($submission, TaskStatusEnum::COMPLETED, 'UPDATE_TASK_STATUS_TO_COMPLETED');

                $room = $submission->task?->room;
                NotificationService::send(
                    $submission->cs_user_id,
                    'checklist_approved',
                    'Laporan Disetujui: ' . ($room?->nama_ruangan ?? 'Ruangan'),
                    'Laporan kebersihan Anda untuk ruang ' . ($room?->nama_ruangan ?? '') . ' telah disetujui.',
                    [
                        'submission_id' => $submission->id,
                        'room_name' => $room?->nama_ruangan,
                        'verified_by' => $user->full_name,
                        'notes' => $finalVerification->catatan_perbaikan,
                    ],
                    'both'
                );

                AuditLogService::log('FINAL_APPROVE_SUBMISSION', 'verifications', $finalVerification->id, null, $finalVerification->toArray());
                AuditLogService::log('UPDATE_SUBMISSION_STATUS_TO_APPROVED', 'checklist_submissions', $submission->id, $oldSubData, $submission->fresh()->toArray());

                $message = 'Laporan kebersihan berhasil disetujui (approval akhir selesai).';
            } else {
                $this->workflow->notifyFinalApprovalNeeded($submission);
                AuditLogService::log('UPDATE_SUBMISSION_STAGE_TO_PENDING_FINAL_APPROVAL', 'checklist_submissions', $submission->id, $oldSubData, $submission->fresh()->toArray());

                $message = 'Laporan berhasil diverifikasi dan diteruskan ke Supervisor tertinggi untuk approval akhir.';
            }

            return $this->success(
                new ChecklistSubmissionResource($submission->fresh()->load(['results.checklistItem', 'latestVerification'])),
                $message
            );
        });
    }

    /**
     * POST /verifications/{submissionId}/reject
     * Mengembalikan laporan ke CS (tahap verifikasi maupun approval akhir).
     */
    public function reject(Request $request, $submissionId)
    {
        $request->validate([
            'catatan_perbaikan' => ['required', 'string'],
        ]);

        $submission = ChecklistSubmission::findOrFail($submissionId);
        $user = $request->user();

        if ($this->workflow->actionFor($user, $submission) === null) {
            return $this->denyAction($submission);
        }

        return DB::transaction(function () use ($request, $submissionId, $user) {
            $submission = ChecklistSubmission::query()->whereKey($submissionId)->lockForUpdate()->first();
            $action = $submission ? $this->workflow->actionFor($user, $submission) : null;
            if ($action === null) {
                return $this->denyAction($submission);
            }

            $stage = $action === VerificationWorkflowService::ACTION_FINAL_APPROVE ? 'final_approval' : 'verification';

            $verification = Verification::create([
                'id' => (string) Str::uuid(),
                'submission_id' => $submission->id,
                'verified_by' => $user->id,
                'role_verifier' => 'supervisor',
                'stage' => $stage,
                'status' => 'rejected',
                'catatan_perbaikan' => $request->catatan_perbaikan,
                'verified_at' => now(),
            ]);

            $oldSubData = $submission->toArray();
            $submission->update([
                'status' => SubmissionStatusEnum::REJECTED,
                'verification_stage' => VerificationStageEnum::REJECTED->value,
            ]);

            $this->updateRelatedTasks($submission, TaskStatusEnum::REJECTED, 'UPDATE_TASK_STATUS_TO_REJECTED');

            $room = $submission->task?->room;
            NotificationService::send(
                $submission->cs_user_id,
                'checklist_rejected',
                'Laporan Ditolak: ' . ($room?->nama_ruangan ?? 'Ruangan'),
                'Laporan kebersihan Anda ditolak. Catatan: ' . $request->catatan_perbaikan,
                [
                    'submission_id' => $submission->id,
                    'room_name' => $room?->nama_ruangan,
                    'verified_by' => $user->full_name,
                    'notes' => $request->catatan_perbaikan,
                ],
                'both'
            );

            AuditLogService::log('VERIFY_SUBMISSION_REJECT', 'verifications', $verification->id, null, $verification->toArray());
            AuditLogService::log('UPDATE_SUBMISSION_STATUS_TO_REJECTED', 'checklist_submissions', $submission->id, $oldSubData, $submission->fresh()->toArray());

            return $this->success(
                new ChecklistSubmissionResource($submission->fresh()->load(['results.checklistItem', 'latestVerification'])),
                'Laporan kebersihan ditolak. Catatan perbaikan telah dikirim ke CS.'
            );
        });
    }

    /**
     * GET /verifications/{id}/foto-inspeksi
     * Stream foto fisik hasil inspeksi supervisor
     */
    public function streamFotoInspeksi($id)
    {
        $verification = Verification::findOrFail($id);

        if (!$verification->foto_inspeksi_path || !Storage::disk('public')->exists($verification->foto_inspeksi_path)) {
            return response()->json(['message' => 'Foto bukti inspeksi tidak ditemukan.'], 404);
        }

        $file = Storage::disk('public')->get($verification->foto_inspeksi_path);
        $mime = Storage::disk('public')->mimeType($verification->foto_inspeksi_path) ?: 'image/jpeg';

        return response($file, 200)->header('Content-Type', $mime);
    }

    // ------------------------------------------------------------------

    private function denyAction(?ChecklistSubmission $submission)
    {
        if (!$submission || $submission->status !== SubmissionStatusEnum::SUBMITTED) {
            return $this->error('Laporan ini sudah diproses sebelumnya.', [], 400);
        }

        $stage = $submission->effectiveStage();
        $message = $stage === VerificationStageEnum::PENDING_FINAL_APPROVAL
            ? 'Laporan ini menunggu approval akhir Supervisor tertinggi. Anda tidak memiliki wewenang untuk menindaklanjutinya.'
            : 'Laporan ini bukan bagian dari antrean verifikasi Anda. Hanya Supervisor Verifikator (atau Supervisor tertinggi pada laporan yang dialihkan) yang dapat memverifikasi.';

        return $this->error($message, [], 403);
    }

    private function storeSlaRatings(Request $request, Verification $verification): void
    {
        if ($request->has('sla_ratings') && is_array($request->sla_ratings)) {
            foreach ($request->sla_ratings as $rating) {
                VerificationSlaRating::create([
                    'id' => (string) Str::uuid(),
                    'verification_id' => $verification->id,
                    'sla_parameter_id' => $rating['sla_parameter_id'],
                    'nilai' => (string) $rating['nilai'],
                ]);
            }
        }
    }

    private function updateRelatedTasks(ChecklistSubmission $submission, TaskStatusEnum $status, string $auditAction): void
    {
        $representativeTask = $submission->task;
        if (!$representativeTask) {
            return;
        }

        $tasks = Task::where('room_id', $representativeTask->room_id)
            ->where('cs_user_id', $representativeTask->cs_user_id)
            ->where('shift_id', $representativeTask->shift_id)
            ->where('tanggal_task', $representativeTask->tanggal_task)
            ->get();

        foreach ($tasks as $task) {
            $oldTaskData = $task->toArray();
            $task->update(['status' => $status]);
            AuditLogService::log($auditAction, 'tasks', $task->id, $oldTaskData, $task->toArray());
        }
    }
}
