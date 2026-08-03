<?php

namespace App\Enums;

enum NotificationChannelEnum: string
{
    case IN_APP = 'in_app';
    case EMAIL = 'email';
    case BOTH = 'both';
}
