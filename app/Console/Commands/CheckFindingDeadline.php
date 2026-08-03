<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Finding;
use App\Models\User;
use App\Models\Notification;
use App\Enums\FindingStatusEnum;
use App\Enums\RoleEnum;
use App\Services\NotificationService;
use Carbon\Carbon;

class CheckFindingDeadline extends Command
{
    protected $signature = 'cams:check-finding-deadline';
    protected $description = 'Mengecek temuan kerusakan yang melewati deadline perbaikan dan mengirim notifikasi ke Supervisor';

    public function handle()
    {
        $this->info("Memeriksa temuan kerusakan yang melewati deadline...");

        $overdueFindings = Finding::whereIn('status', [FindingStatusEnum::OPEN, FindingStatusEnum::IN_PROGRESS])
            ->whereNotNull('deadline_perbaikan')
            ->whereDate('deadline_perbaikan', '<', today('Asia/Jakarta'))
            ->with(['room', 'assignee'])
            ->get();

        if ($overdueFindings->isEmpty()) {
            $this->info("Tidak ada temuan kerusakan yang melewati deadline.");
            return self::SUCCESS;
        }

        $this->info("Menemukan " . $overdueFindings->count() . " temuan melewati deadline. Memproses...");

        // Ambil supervisor aktif
        $supervisors = User::whereHas('roles', function ($q) {
            $q->where('name', RoleEnum::SUPERVISOR->value);
        })->where('is_active', true)->get();

        if ($supervisors->isEmpty()) {
            $this->warn("Tidak ada user Supervisor aktif.");
            return self::SUCCESS;
        }

        $count = 0;
        foreach ($overdueFindings as $finding) {
            // Hindari notifikasi ganda dalam 24 jam terakhir untuk temuan yang sama
            $alreadyNotified = Notification::where('type', 'FINDING_OVERDUE')
                ->where('data->finding_id', $finding->id)
                ->where('created_at', '>=', now('Asia/Jakarta')->subHours(24))
                ->exists();

            if ($alreadyNotified) {
                continue;
            }

            $roomName = $finding->room?->nama_ruangan ?? 'Ruangan';
            $roomCode = $finding->room?->kode_ruangan ?? '-';
            $assigneeName = $finding->assigned_to_external ?? ($finding->assignee?->full_name ?? 'Belum Ditugaskan');
            $deadlineStr = $finding->deadline_perbaikan->toDateString();

            foreach ($supervisors as $supervisor) {
                NotificationService::send(
                    $supervisor->id,
                    'FINDING_OVERDUE',
                    "Overdue Temuan: Ruang {$roomName}",
                    "Temuan perbaikan di ruangan {$roomName} ({$roomCode}) yang ditugaskan kepada {$assigneeName} telah melewati deadline perbaikan ({$deadlineStr}).",
                    [
                        'finding_id' => $finding->id,
                        'room_name' => $roomName,
                        'room_code' => $roomCode,
                        'assignee_name' => $assigneeName,
                        'deadline_perbaikan' => $deadlineStr,
                    ],
                    'both'
                );
            }
            $count++;
        }

        $this->info("Selesai. Notifikasi dikirim untuk {$count} temuan.");
        return self::SUCCESS;
    }
}
