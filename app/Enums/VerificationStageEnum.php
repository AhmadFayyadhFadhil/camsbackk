<?php

namespace App\Enums;

/**
 * Tahap workflow verifikasi Checklist Harian.
 * Kolom `status` submission (submitted/approved/rejected) tetap dipakai modul lain;
 * enum ini hanya merinci tahap saat status = submitted.
 */
enum VerificationStageEnum: string
{
    case PENDING_VERIFICATION = 'pending_verification';       // menunggu verifikasi Supervisor Verifikator (atau Winaryo bila dialihkan)
    case PENDING_FINAL_APPROVAL = 'pending_final_approval';   // lolos verifikasi, menunggu approval akhir Winaryo
    case COMPLETED = 'completed';                             // approval akhir selesai
    case REJECTED = 'rejected';                               // dikembalikan ke CS
}
