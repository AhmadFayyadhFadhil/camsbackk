<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Verification;
use App\Models\ChecklistSubmission;
use App\Enums\RoleEnum;
use App\Services\VerificationWorkflowService;

/**
 * Workflow Checklist Harian: PIC Ruangan tidak terlibat dalam verifikasi maupun approval.
 * Hak verifikasi/approval ditentukan oleh level hierarki Supervisor (lihat VerificationWorkflowService::actionFor).
 */
class VerificationPolicy
{
    /**
     * Boleh memverifikasi / approval akhir hanya bila punya aksi pada tahap laporan saat ini.
     */
    public function create(User $user, ChecklistSubmission $submission): bool
    {
        return app(VerificationWorkflowService::class)->actionFor($user, $submission) !== null;
    }

    /**
     * Determine whether the user can view the verification.
     */
    public function view(User $user, Verification $verification): bool
    {
        if ($user->hasRole(RoleEnum::ADMIN) || $user->hasRole(RoleEnum::SUPERVISOR)) {
            return true;
        }

        $submission = $verification->submission;
        if (!$submission) {
            return false;
        }

        if ($user->hasRole(RoleEnum::CS)) {
            return $submission->cs_user_id === $user->id;
        }

        return false;
    }
}
