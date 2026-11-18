<?php

namespace App\Enums;

/**
 * Level hierarki di dalam role `supervisor` (tanpa membuat role baru).
 */
enum SupervisorLevelEnum: string
{
    case HEAD = 'head';               // Pak Winaryo - approval akhir & pengatur status Pak Kusnan
    case VERIFIER = 'verifier';       // Pak Kusnan  - verifikasi Checklist Harian
    case OPERATIONAL = 'operational'; // Pak Denny   - operasional, tanpa hak verifikasi/approval
}
