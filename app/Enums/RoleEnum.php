<?php

namespace App\Enums;

enum RoleEnum: string
{
    case ADMIN = 'admin';
    case SUPERVISOR = 'supervisor';
    case PIC = 'pic';
    case CS = 'cs';
    case OB = 'ob';
    case GUEST = 'guest';
}
