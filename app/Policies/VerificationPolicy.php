<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Verification;
use App\Models\ChecklistSubmission;
use App\Enums\RoleEnum;

class VerificationPolicy
{
    /**
     * Determine whether the user can create verifications for a submission.
     */
    public function create(User $user, ChecklistSubmission $submission): bool
    {
        if ($user->hasRole(RoleEnum::ADMIN) || $user->hasRole(RoleEnum::SUPERVISOR)) {
            return true;
        }

        if ($user->hasRole(RoleEnum::PIC)) {
            $task = $submission->task;
            if (!$task) {
                return false;
            }
            if ($task->room && $task->room->pic_user_id === $user->id) {
                return true;
            }
            return $user->picHistories()
                ->where('room_id', $task->room_id)
                ->where(function ($query) {
                    $query->whereNull('tanggal_selesai')
                          ->orWhere('tanggal_selesai', '>=', today()->toDateString());
                })
                ->exists();
        }

        return false;
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
            return $submission->user_id === $user->id;
        }

        if ($user->hasRole(RoleEnum::PIC)) {
            $task = $submission->task;
            if (!$task) {
                return false;
            }
            if ($task->room && $task->room->pic_user_id === $user->id) {
                return true;
            }
            return $user->picHistories()
                ->where('room_id', $task->room_id)
                ->where(function ($query) {
                    $query->whereNull('tanggal_selesai')
                          ->orWhere('tanggal_selesai', '>=', today()->toDateString());
                })
                ->exists();
        }

        return false;
    }
}
