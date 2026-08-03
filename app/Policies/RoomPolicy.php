<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Room;
use App\Enums\RoleEnum;

class RoomPolicy
{
    /**
     * Determine whether the user can view/download the QR code of the room.
     */
    public function viewQrCode(User $user, Room $room): bool
    {
        return $user->hasRole(RoleEnum::ADMIN);
    }

    /**
     * Determine whether the user can view rooms.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the room details.
     */
    public function view(User $user, Room $room): bool
    {
        return true;
    }
}
