<?php

namespace App\Enums;

enum TaskStatusEnum: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case WAITING_VERIFICATION = 'waiting_verification';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
    case OVERDUE = 'overdue';
}
