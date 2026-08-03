<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ChecklistSubmission;
use App\Enums\RoleEnum;

class SubmissionPolicy
{
    /**
     * Determine whether the user can view the checklist submission.
     */
    public function view(User $user, ChecklistSubmission $submission): bool
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

        if ($user->hasRole(RoleEnum::CS)) {
            return $submission->cs_user_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create checklist submissions.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(RoleEnum::CS);
    }

    /**
     * Determine whether the user can update the checklist submission.
     */
    public function update(User $user, ChecklistSubmission $submission): bool
    {
        if ($user->hasRole(RoleEnum::CS)) {
            return $submission->cs_user_id === $user->id;
        }

        return $user->hasRole(RoleEnum::ADMIN) || $user->hasRole(RoleEnum::SUPERVISOR);
    }
}
