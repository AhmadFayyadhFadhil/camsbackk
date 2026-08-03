<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ChecklistSubmission;
use App\Models\User;
use App\Models\Notification;
use App\Enums\RoleEnum;
use App\Enums\SubmissionStatusEnum;
use App\Services\NotificationService;
use Carbon\Carbon;

class CheckEscalations extends Command
{
    protected $signature = 'cams:check-escalations';
    protected $description = 'Otomatisasi eskalasi laporan kebersihan yang belum diverifikasi oleh PIC melewati batas waktu toleransi';

    public function handle()
    {
        $this->info('Memeriksa eskalasi laporan kebersihan...');

        $timeoutMinutes = (int) \App\Helpers\SettingHelper::get('escalation_pic_timeout_minutes', 120);
        $thresholdTime = Carbon::now('Asia/Jakarta')->subMinutes($timeoutMinutes);

        // Ambil submission pending (submitted)
        $submissions = ChecklistSubmission::where('status', SubmissionStatusEnum::SUBMITTED)
            ->where('submitted_at', '<=', $thresholdTime)
            ->whereDoesntHave('verifications')
            ->with(['task.room.pic', 'cs'])
            ->get();

        if ($submissions->isEmpty()) {
            $this->info('Tidak ada laporan kebersihan pending yang melewati batas toleransi.');
            return self::SUCCESS;
        }

        // Ambil semua user Supervisor
        $supervisors = User::whereHas('roles', function ($q) {
            $q->where('name', RoleEnum::SUPERVISOR->value);
        })->where('is_active', true)->get();

        if ($supervisors->isEmpty()) {
            $this->warn('Tidak ada user Supervisor ditemukan.');
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($submissions as $submission) {
            // Cek apakah notifikasi ESCALATION_PIC untuk submission ini sudah pernah dikirim
            $alreadyEscalated = Notification::where('type', 'ESCALATION_PIC')
                ->where('data->submission_id', $submission->id)
                ->exists();

            if ($alreadyEscalated) {
                continue;
            }

            $roomName = $submission->task?->room?->nama_ruangan ?? 'Ruangan';
            $roomCode = $submission->task?->room?->kode_ruangan ?? '-';
            $picName = $submission->task?->room?->pic?->full_name ?? 'Unassigned';
            $csName = $submission->cs?->full_name ?? 'CS';
            $submissionTimeStr = $submission->submitted_at->toDateTimeString();

            foreach ($supervisors as $supervisor) {
                NotificationService::send(
                    $supervisor->id,
                    'ESCALATION_PIC',
                    "Eskalasi Verifikasi: Ruang {$roomName}",
                    "Laporan pengerjaan kebersihan ruangan {$roomName} ({$roomCode}) oleh CS {$csName} pada {$submissionTimeStr} telah melewati batas toleransi {$timeoutMinutes} menit dan belum diverifikasi oleh PIC {$picName}.",
                    [
                        'submission_id' => $submission->id,
                        'room_name' => $roomName,
                        'room_code' => $roomCode,
                        'cs_name' => $csName,
                        'pic_name' => $picName,
                        'submission_time' => $submissionTimeStr,
                    ],
                    'both'
                );
            }
            $count++;
        }

        $this->info("Eskalasi selesai. {$count} laporan berhasil dieskalasikan.");
        return self::SUCCESS;
    }
}
