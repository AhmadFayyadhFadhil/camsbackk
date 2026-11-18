<?php

namespace App\Services;

use App\Enums\RoleEnum;
use App\Enums\SubmissionStatusEnum;
use App\Enums\SupervisorLevelEnum;
use App\Enums\VerificationStageEnum;
use App\Helpers\SettingHelper;
use App\Models\ChecklistSubmission;
use App\Models\SubmissionRedirect;
use App\Models\User;
use App\Models\VerifierDutyLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Inti workflow verifikasi Checklist Harian Cleaning Service:
 *
 *   CS submit -> Supervisor Verifikator (Pak Kusnan) -> Supervisor Tertinggi (Pak Winaryo)
 *   approval akhir -> Selesai
 *
 * Bila Pak Kusnan tidak bertugas atau melewati SLA (24 jam kalender), laporan dialihkan
 * ke Pak Winaryo. PIC Ruangan tidak terlibat. Pak Denny (operational) tidak punya hak
 * verifikasi maupun approval.
 */
class VerificationWorkflowService
{
    public const ACTION_VERIFY = 'verify';
    public const ACTION_FINAL_APPROVE = 'final_approve';

    // ------------------------------------------------------------------
    // Konfigurasi & identitas
    // ------------------------------------------------------------------

    public static function slaHours(): int
    {
        $hours = (int) SettingHelper::get('verification_sla_hours', config('cams.verification.sla_hours', 24));
        return max(1, $hours);
    }

    public function deadlineFor(Carbon $submittedAt): Carbon
    {
        return $submittedAt->copy()->addHours(self::slaHours());
    }

    private function userByLevel(SupervisorLevelEnum $level): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->where('supervisor_level', $level->value)
            ->whereHas('roles', fn ($q) => $q->where('name', RoleEnum::SUPERVISOR->value))
            ->orderBy('created_at')
            ->first();
    }

    /** Pak Winaryo (Supervisor tertinggi). */
    public function head(): ?User
    {
        return $this->userByLevel(SupervisorLevelEnum::HEAD);
    }

    /** Pak Kusnan (Supervisor Verifikator). */
    public function verifier(): ?User
    {
        return $this->userByLevel(SupervisorLevelEnum::VERIFIER);
    }

    public function isOnDuty(?User $verifier): bool
    {
        return $verifier !== null && $verifier->is_active && $verifier->is_on_duty;
    }

    // ------------------------------------------------------------------
    // Routing laporan baru / kirim ulang
    // ------------------------------------------------------------------

    /**
     * Tentukan penangani tahap verifikasi untuk laporan yang baru di-submit (atau di-resubmit).
     * Aman dipanggil berulang (idempotent) untuk laporan yang belum terarah.
     */
    public function routeSubmission(ChecklistSubmission $submission, bool $notify = true): void
    {
        $verifier = $this->verifier();
        $head = $this->head();

        $submittedAt = $submission->submitted_at ?? now();
        $base = [
            'verification_stage' => VerificationStageEnum::PENDING_VERIFICATION->value,
            'verification_deadline_at' => $this->deadlineFor($submittedAt),
            'verified_at' => null,
            'verified_by' => null,
            'final_approved_at' => null,
            'final_approved_by' => null,
            'redirected_at' => null,
            'redirect_reason' => null,
        ];

        if ($this->isOnDuty($verifier)) {
            $submission->update(array_merge($base, ['verifier_id' => $verifier->id]));
            if ($notify) {
                $this->notifySubmitted($verifier, $submission, false);
            }
            return;
        }

        if ($head) {
            // Pak Kusnan tidak bertugas / belum terkonfigurasi: langsung ke Pak Winaryo.
            $reason = $verifier ? SubmissionRedirect::REASON_VERIFIER_OFF : SubmissionRedirect::REASON_VERIFIER_UNAVAILABLE;
            $submission->update(array_merge($base, [
                'verifier_id' => $head->id,
                'redirected_at' => now(),
                'redirect_reason' => $reason,
            ]));
            SubmissionRedirect::create([
                'submission_id' => $submission->id,
                'from_user_id' => $verifier?->id,
                'to_user_id' => $head->id,
                'reason' => $reason,
                'redirected_by' => null,
                'note' => $verifier
                    ? 'Supervisor Verifikator tidak bertugas saat laporan di-submit.'
                    : 'Supervisor Verifikator belum terkonfigurasi saat laporan di-submit.',
                'redirected_at' => now(),
            ]);
            if ($notify) {
                $this->notifySubmitted($head, $submission, true);
            }
            return;
        }

        // Tidak ada verifikator maupun Supervisor tertinggi: laporan menunggu, dicatat agar terlihat di log.
        $submission->update(array_merge($base, ['verifier_id' => null]));
        Log::warning('Workflow verifikasi: Supervisor Verifikator/Supervisor tertinggi belum dikonfigurasi. Jalankan ChecklistVerificationHierarchySeeder.', [
            'submission_id' => $submission->id,
        ]);
    }

    /**
     * Arahkan laporan antre yang belum punya tahap/penangani (data lama atau akun baru dikonfigurasi).
     */
    public function assignUnrouted(): int
    {
        $count = 0;
        $submissions = ChecklistSubmission::query()
            ->where('status', SubmissionStatusEnum::SUBMITTED)
            ->where(function (Builder $q) {
                $q->whereNull('verification_stage')
                  ->orWhere(function (Builder $q2) {
                      $q2->where('verification_stage', VerificationStageEnum::PENDING_VERIFICATION->value)
                         ->whereNull('verifier_id');
                  });
            })
            ->get();

        foreach ($submissions as $submission) {
            // Jangan menimpa laporan yang sudah lolos verifikasi
            if ($submission->verification_stage === VerificationStageEnum::PENDING_FINAL_APPROVAL->value) {
                continue;
            }
            if (!$this->verifier() && !$this->head()) {
                break; // belum ada akun hierarki; hindari loop sia-sia
            }
            $this->routeSubmission($submission);
            $count++;
        }

        return $count;
    }

    // ------------------------------------------------------------------
    // Hak akses
    // ------------------------------------------------------------------

    /**
     * Aksi yang boleh dilakukan user pada laporan ini: verify | final_approve | null.
     */
    public function actionFor(User $user, ChecklistSubmission $submission): ?string
    {
        if ($submission->status !== SubmissionStatusEnum::SUBMITTED) {
            return null;
        }

        $stage = $submission->effectiveStage();

        if ($stage === VerificationStageEnum::PENDING_VERIFICATION) {
            if ($user->isVerifierSupervisor()) {
                return ($submission->verifier_id === null || $submission->verifier_id === $user->id)
                    ? self::ACTION_VERIFY : null;
            }
            if ($user->isHeadSupervisor()) {
                // Winaryo hanya menangani verifikasi pada laporan yang dialihkan kepadanya.
                return $submission->verifier_id === $user->id ? self::ACTION_VERIFY : null;
            }
            return null;
        }

        if ($stage === VerificationStageEnum::PENDING_FINAL_APPROVAL) {
            return $user->isHeadSupervisor() ? self::ACTION_FINAL_APPROVE : null;
        }

        return null;
    }

    /** Admin & seluruh Supervisor boleh melihat antrean (read-only bila tidak punya aksi). */
    public function canViewQueue(User $user): bool
    {
        return $user->hasRole(RoleEnum::ADMIN) || $user->hasRole(RoleEnum::SUPERVISOR);
    }

    /** Hanya Pak Winaryo atau Admin yang boleh mengatur status Pak Kusnan. */
    public function canManageVerifierDuty(User $user): bool
    {
        return $user->hasRole(RoleEnum::ADMIN) || $user->isHeadSupervisor();
    }

    // ------------------------------------------------------------------
    // Antrean
    // ------------------------------------------------------------------

    private function scopePendingVerificationStage(Builder $q): Builder
    {
        return $q->where(function (Builder $s) {
            $s->whereNull('verification_stage')
              ->orWhere('verification_stage', VerificationStageEnum::PENDING_VERIFICATION->value);
        });
    }

    /**
     * @param string $scope mine = yang dapat saya tindak lanjuti | all = seluruh antrean (read-only)
     */
    public function pendingQuery(User $user, string $scope = 'mine'): Builder
    {
        $query = ChecklistSubmission::query()->where('status', SubmissionStatusEnum::SUBMITTED);

        if ($scope === 'all') {
            return $query;
        }

        if ($user->isVerifierSupervisor()) {
            return $this->scopePendingVerificationStage($query)->where(function (Builder $q) use ($user) {
                $q->whereNull('verifier_id')->orWhere('verifier_id', $user->id);
            });
        }

        if ($user->isHeadSupervisor()) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('verification_stage', VerificationStageEnum::PENDING_FINAL_APPROVAL->value)
                  ->orWhere(function (Builder $q2) use ($user) {
                      $this->scopePendingVerificationStage($q2)->where('verifier_id', $user->id);
                  });
            });
        }

        return $query->whereRaw('1 = 0');
    }

    // ------------------------------------------------------------------
    // Pengalihan ke Supervisor tertinggi
    // ------------------------------------------------------------------

    /**
     * Alihkan satu laporan ke Pak Winaryo. Idempotent: tidak membuat duplikat bila sudah dialihkan.
     */
    public function redirectToHead(ChecklistSubmission $submission, string $reason, ?User $actor = null, ?string $note = null): bool
    {
        $head = $this->head();
        if (!$head) {
            Log::warning('Pengalihan dibatalkan: Supervisor tertinggi belum dikonfigurasi.', ['submission_id' => $submission->id]);
            return false;
        }

        return DB::transaction(function () use ($submission, $reason, $actor, $note, $head) {
            $fresh = ChecklistSubmission::query()->whereKey($submission->id)->lockForUpdate()->first();

            if (!$fresh
                || $fresh->status !== SubmissionStatusEnum::SUBMITTED
                || $fresh->effectiveStage() !== VerificationStageEnum::PENDING_VERIFICATION
                || $fresh->verifier_id === $head->id) {
                return false;
            }

            $fromId = $fresh->verifier_id;
            $fresh->update([
                'verification_stage' => VerificationStageEnum::PENDING_VERIFICATION->value,
                'verifier_id' => $head->id,
                'redirected_at' => now(),
                'redirect_reason' => $reason,
            ]);

            SubmissionRedirect::create([
                'submission_id' => $fresh->id,
                'from_user_id' => $fromId,
                'to_user_id' => $head->id,
                'reason' => $reason,
                'redirected_by' => $actor?->id,
                'note' => $note,
                'redirected_at' => now(),
            ]);

            AuditLogService::log(
                'REDIRECT_SUBMISSION_TO_HEAD',
                'checklist_submissions',
                $fresh->id,
                ['verifier_id' => $fromId],
                ['verifier_id' => $head->id, 'reason' => $reason, 'note' => $note, 'by' => $actor?->id ?? 'system']
            );

            $fresh->loadMissing(['task.room', 'cs']);
            $roomName = $fresh->task?->room?->nama_ruangan ?? 'Ruangan';
            $reasonLabel = self::reasonLabel($reason);

            NotificationService::send(
                $head->id,
                'checklist_redirected',
                "Pengalihan Verifikasi Laporan Kebersihan: {$roomName}",
                "Laporan kebersihan ruang {$roomName} dialihkan kepada Anda. Alasan: {$reasonLabel}.",
                [
                    'submission_id' => $fresh->id,
                    'room_name' => $roomName,
                    'reason' => $reason,
                ],
                'in_app'
            );

            return true;
        });
    }

    /**
     * Scheduler: alihkan laporan yang melewati SLA verifikasi (24 jam kalender sejak submit).
     */
    public function redirectOverdue(): int
    {
        $head = $this->head();
        if (!$head) {
            return 0;
        }

        $now = now();
        $legacyCutoff = $now->copy()->subHours(self::slaHours());

        $query = $this->scopePendingVerificationStage(
            ChecklistSubmission::query()->where('status', SubmissionStatusEnum::SUBMITTED)
        )
            ->where(function (Builder $q) use ($head) {
                $q->whereNull('verifier_id')->orWhere('verifier_id', '!=', $head->id);
            })
            ->where(function (Builder $q) use ($now, $legacyCutoff) {
                $q->where('verification_deadline_at', '<=', $now)
                  ->orWhere(function (Builder $q2) use ($legacyCutoff) {
                      $q2->whereNull('verification_deadline_at')->where('submitted_at', '<=', $legacyCutoff);
                  });
            });

        $count = 0;
        foreach ($query->get() as $submission) {
            if ($this->redirectToHead(
                $submission,
                SubmissionRedirect::REASON_SLA_OVERDUE,
                null,
                'Melewati SLA verifikasi ' . self::slaHours() . ' jam kalender sejak submit.'
            )) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Alihkan seluruh laporan antre milik verifikator (dipakai saat verifikator diset tidak bertugas).
     */
    public function redirectPendingForVerifier(User $verifier, ?User $actor, ?string $note = null): int
    {
        $submissions = $this->scopePendingVerificationStage(
            ChecklistSubmission::query()->where('status', SubmissionStatusEnum::SUBMITTED)
        )
            ->where(function (Builder $q) use ($verifier) {
                $q->whereNull('verifier_id')->orWhere('verifier_id', $verifier->id);
            })
            ->get();

        $count = 0;
        foreach ($submissions as $submission) {
            if ($this->redirectToHead($submission, SubmissionRedirect::REASON_VERIFIER_OFF, $actor, $note)) {
                $count++;
            }
        }
        return $count;
    }

    // ------------------------------------------------------------------
    // Status ketersediaan Supervisor Verifikator
    // ------------------------------------------------------------------

    /**
     * @return array{is_on_duty:bool, redirected:int}
     */
    public function setVerifierDuty(User $verifier, bool $onDuty, ?string $reason, User $actor, bool $redirectPending = true): array
    {
        $redirected = 0;

        DB::transaction(function () use ($verifier, $onDuty, $reason, $actor) {
            $old = (bool) $verifier->is_on_duty;
            $verifier->update(['is_on_duty' => $onDuty]);

            VerifierDutyLog::create([
                'user_id' => $verifier->id,
                'is_on_duty' => $onDuty,
                'reason' => $reason,
                'changed_by' => $actor->id,
                'changed_at' => now(),
            ]);

            AuditLogService::log(
                'UPDATE_VERIFIER_DUTY_STATUS',
                'users',
                $verifier->id,
                ['is_on_duty' => $old],
                ['is_on_duty' => $onDuty, 'reason' => $reason]
            );
        });

        if (!$onDuty && $redirectPending) {
            $redirected = $this->redirectPendingForVerifier(
                $verifier,
                $actor,
                $reason ? "Supervisor Verifikator tidak bertugas: {$reason}" : 'Supervisor Verifikator diset tidak bertugas.'
            );
        }

        if ($verifier->id !== $actor->id) {
            $statusText = $onDuty ? 'bertugas' : 'tidak bertugas';
            NotificationService::send(
                $verifier->id,
                'checklist_duty_status',
                'Status Ketersediaan Verifikasi Diubah',
                "{$actor->full_name} mengubah status Anda menjadi {$statusText}." . ($reason ? " Alasan: {$reason}" : ''),
                ['is_on_duty' => $onDuty],
                'in_app'
            );
        }

        return ['is_on_duty' => $onDuty, 'redirected' => $redirected];
    }

    // ------------------------------------------------------------------
    // Ringkasan & presentasi
    // ------------------------------------------------------------------

    public static function reasonLabel(?string $reason): ?string
    {
        return match ($reason) {
            SubmissionRedirect::REASON_VERIFIER_OFF => 'Supervisor Verifikator tidak bertugas',
            SubmissionRedirect::REASON_SLA_OVERDUE => 'Melewati SLA verifikasi ' . self::slaHours() . ' jam kalender',
            SubmissionRedirect::REASON_VERIFIER_UNAVAILABLE => 'Supervisor Verifikator belum tersedia',
            default => null,
        };
    }

    public static function stageLabel(VerificationStageEnum $stage): string
    {
        return match ($stage) {
            VerificationStageEnum::PENDING_VERIFICATION => 'Menunggu Verifikasi Supervisor',
            VerificationStageEnum::PENDING_FINAL_APPROVAL => 'Menunggu Approval Akhir',
            VerificationStageEnum::COMPLETED => 'Selesai',
            VerificationStageEnum::REJECTED => 'Dikembalikan ke CS',
        };
    }

    /**
     * Blok `workflow` untuk response API.
     */
    public function describe(ChecklistSubmission $submission, ?User $user = null): array
    {
        $submission->loadMissing(['verifier:id,full_name', 'verifiedByUser:id,full_name', 'finalApprover:id,full_name']);

        $stage = $submission->effectiveStage();
        $deadline = $submission->verification_deadline_at
            ?? ($submission->submitted_at ? $this->deadlineFor($submission->submitted_at) : null);
        $isPendingVerification = $submission->status === SubmissionStatusEnum::SUBMITTED
            && $stage === VerificationStageEnum::PENDING_VERIFICATION;

        $lastRedirect = $submission->relationLoaded('redirects')
            ? $submission->redirects->sortByDesc('redirected_at')->first()
            : $submission->redirects()->with(['fromUser:id,full_name', 'toUser:id,full_name', 'actor:id,full_name'])->latest('redirected_at')->first();

        $action = $user ? $this->actionFor($user, $submission) : null;

        return [
            'stage' => $stage->value,
            'stage_label' => self::stageLabel($stage),
            'sla_hours' => self::slaHours(),
            'verifier_id' => $submission->verifier_id,
            'verifier_name' => $submission->verifier?->full_name,
            'deadline_at' => $deadline?->toIso8601String(),
            'is_overdue' => $isPendingVerification && $deadline !== null && now()->greaterThan($deadline),
            'hours_remaining' => $isPendingVerification && $deadline !== null
                ? round(now()->diffInMinutes($deadline, false) / 60, 1)
                : null,
            'is_redirected' => $submission->redirected_at !== null,
            'redirected_at' => $submission->redirected_at?->toIso8601String(),
            'redirect_reason' => $submission->redirect_reason,
            'redirect_reason_label' => self::reasonLabel($submission->redirect_reason),
            'redirect_note' => $lastRedirect?->note,
            'redirected_by_name' => $lastRedirect?->actor?->full_name,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'verified_at' => $submission->verified_at?->toIso8601String(),
            'verified_by_name' => $submission->verifiedByUser?->full_name,
            'final_approved_at' => $submission->final_approved_at?->toIso8601String(),
            'final_approved_by_name' => $submission->finalApprover?->full_name,
            'can_act' => $action !== null,
            'action' => $action,
        ];
    }

    /**
     * Ringkasan untuk panel status & dashboard.
     */
    public function summary(User $user): array
    {
        $verifier = $this->verifier();
        $head = $this->head();
        $now = now();

        $pendingAll = ChecklistSubmission::query()->where('status', SubmissionStatusEnum::SUBMITTED);
        $pendingVerification = $this->scopePendingVerificationStage(clone $pendingAll);

        $overdue = (clone $pendingVerification)->where(function (Builder $q) use ($now) {
            $q->where('verification_deadline_at', '<', $now)
              ->orWhere(function (Builder $q2) use ($now) {
                  $q2->whereNull('verification_deadline_at')
                     ->where('submitted_at', '<', $now->copy()->subHours(self::slaHours()));
              });
        })->count();

        $lastLog = $verifier
            ? VerifierDutyLog::query()->where('user_id', $verifier->id)->with('changer:id,full_name')->latest('changed_at')->first()
            : null;

        return [
            'sla_hours' => self::slaHours(),
            'my_level' => $user->supervisorLevel()?->value,
            'can_manage_verifier_duty' => $this->canManageVerifierDuty($user),
            'verifier' => $verifier ? [
                'id' => $verifier->id,
                'name' => $verifier->full_name,
                'is_on_duty' => (bool) $verifier->is_on_duty,
                'last_change' => $lastLog ? [
                    'is_on_duty' => $lastLog->is_on_duty,
                    'reason' => $lastLog->reason,
                    'changed_by' => $lastLog->changer?->full_name,
                    'changed_at' => $lastLog->changed_at?->toIso8601String(),
                ] : null,
            ] : null,
            'head' => $head ? ['id' => $head->id, 'name' => $head->full_name] : null,
            'counts' => [
                'my_queue' => $this->pendingQuery($user, 'mine')->count(),
                'pending_verification' => (clone $pendingVerification)->count(),
                'pending_final_approval' => (clone $pendingAll)
                    ->where('verification_stage', VerificationStageEnum::PENDING_FINAL_APPROVAL->value)->count(),
                'overdue' => $overdue,
                'redirected_to_head' => (clone $pendingVerification)->whereNotNull('redirected_at')->count(),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Notifikasi
    // ------------------------------------------------------------------

    private function notifySubmitted(User $recipient, ChecklistSubmission $submission, bool $redirected): void
    {
        $submission->loadMissing(['task.room', 'cs']);
        $roomName = $submission->task?->room?->nama_ruangan ?? 'Ruangan';
        $csName = $submission->cs?->full_name ?? 'CS';

        NotificationService::send(
            $recipient->id,
            'checklist_submitted',
            "Laporan Kebersihan Baru: {$roomName}",
            "CS {$csName} menyerahkan laporan kebersihan ruang {$roomName}."
                . ($redirected ? ' Laporan langsung dialihkan kepada Anda karena Supervisor Verifikator tidak bertugas.' : '')
                . ' Batas verifikasi ' . self::slaHours() . ' jam kalender.',
            [
                'submission_id' => $submission->id,
                'room_name' => $roomName,
                'room_code' => $submission->task?->room?->kode_ruangan,
                'cs_name' => $csName,
                'submission_time' => $submission->submitted_at?->toDateTimeString(),
            ],
            'both'
        );
    }

    public function notifyFinalApprovalNeeded(ChecklistSubmission $submission): void
    {
        $head = $this->head();
        if (!$head) {
            Log::warning('Approval akhir tidak dapat diberitahukan: Supervisor tertinggi belum dikonfigurasi.', ['submission_id' => $submission->id]);
            return;
        }

        $submission->loadMissing(['task.room']);
        $roomName = $submission->task?->room?->nama_ruangan ?? 'Ruangan';

        NotificationService::send(
            $head->id,
            'checklist_final_approval',
            "Menunggu Approval Akhir Laporan Kebersihan: {$roomName}",
            "Laporan kebersihan ruang {$roomName} telah diverifikasi dan menunggu approval akhir Anda.",
            ['submission_id' => $submission->id, 'room_name' => $roomName],
            'in_app'
        );
    }
}
