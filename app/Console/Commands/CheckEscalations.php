<?php

namespace App\Console\Commands;

use App\Services\VerificationWorkflowService;
use Illuminate\Console\Command;

/**
 * Scheduler workflow verifikasi Checklist Harian.
 *
 * 1. Mengarahkan laporan antre yang belum punya penangani (data lama / akun hierarki baru dikonfigurasi).
 * 2. Mengalihkan laporan yang belum diverifikasi Pak Kusnan dalam SLA (24 jam kalender sejak submit)
 *    kepada Pak Winaryo. Idempotent: laporan yang sudah dialihkan tidak diproses ulang.
 *
 * Menggantikan eskalasi lama ke PIC (SLA 120 menit) yang tidak lagi dipakai.
 */
class CheckEscalations extends Command
{
    protected $signature = 'cams:check-escalations';
    protected $description = 'Alihkan laporan Checklist Harian yang melewati SLA verifikasi (24 jam kalender) dari Supervisor Verifikator ke Supervisor tertinggi';

    public function handle(VerificationWorkflowService $workflow): int
    {
        $this->info('Memeriksa SLA verifikasi Checklist Harian (' . VerificationWorkflowService::slaHours() . ' jam kalender)...');

        $assigned = $workflow->assignUnrouted();
        if ($assigned > 0) {
            $this->info("{$assigned} laporan antre diarahkan ke penangani verifikasi.");
        }

        if (!$workflow->head()) {
            $this->warn('Supervisor tertinggi belum dikonfigurasi (jalankan ChecklistVerificationHierarchySeeder). Pengalihan dilewati.');
            return self::SUCCESS;
        }

        $redirected = $workflow->redirectOverdue();
        $this->info("Selesai. {$redirected} laporan dialihkan ke Supervisor tertinggi karena melewati SLA.");

        return self::SUCCESS;
    }
}
