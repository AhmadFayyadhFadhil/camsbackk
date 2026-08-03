<?php

namespace App\Enums;

enum SubmissionStatusEnum: string
{
    case SUBMITTED = 'submitted';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
}
