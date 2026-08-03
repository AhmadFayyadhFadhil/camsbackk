<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Task;
use App\Enums\RoleEnum;

class TaskPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Task $task): bool
    {
        if ($user->hasRole(RoleEnum::ADMIN) || $user->hasRole(RoleEnum::SUPERVISOR)) {
            return true;
        }

        if ($user->hasRole(RoleEnum::PIC)) {
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
            return $task->cs_user_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can update/submit the task.
     */
    public function update(User $user, Task $task): bool
    {
        if ($user->hasRole(RoleEnum::CS)) {
            return $task->cs_user_id === $user->id;
        }

        return $user->hasRole(RoleEnum::ADMIN) || $user->hasRole(RoleEnum::SUPERVISOR);
    }
}
