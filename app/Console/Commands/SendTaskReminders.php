<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Models\Notification;
use App\Enums\TaskStatusEnum;
use App\Services\NotificationService;
use Carbon\Carbon;

class SendTaskReminders extends Command
{
    protected $signature = 'cams:send-reminders';
    protected $description = 'Kirim pengingat ke CS 60 menit sebelum deadline tugas berakhir';

    public function handle()
    {
        $now = Carbon::now('Asia/Jakarta');
        $reminderBeforeMinutes = (int) \App\Helpers\SettingHelper::get('task_reminder_before_end_minutes', 60);
        $thresholdTime = $now->copy()->addMinutes($reminderBeforeMinutes);

        // Cari task pending / in_progress yang deadline-nya mendekati
        $upcomingTasks = Task::whereIn('status', [TaskStatusEnum::PENDING, TaskStatusEnum::IN_PROGRESS])
            ->whereBetween('due_datetime', [$now, $thresholdTime])
            ->with(['room', 'cs'])
            ->get();

        if ($upcomingTasks->isEmpty()) {
            $this->info("Tidak ada tugas mendesak yang memerlukan pengingat.");
            return self::SUCCESS;
        }

        $this->info("Menemukan " . $upcomingTasks->count() . " tugas mendesak. Memproses pengingat...");

        foreach ($upcomingTasks as $task) {
            if (!$task->cs_user_id) {
                continue;
            }

            // Cek apakah reminder sudah dikirim untuk tugas ini dalam 2 jam terakhir
            $reminderExists = Notification::where('user_id', $task->cs_user_id)
                ->where('type', 'TASK_REMINDER')
                ->where('data->task_id', $task->id)
                ->where('created_at', '>=', now('Asia/Jakarta')->subHours(2))
                ->exists();

            if ($reminderExists) {
                continue;
            }

            // Kirim notifikasi pengingat ke CS
            NotificationService::send(
                $task->cs_user_id,
                'TASK_REMINDER',
                'Pengingat Tugas: ' . ($task->room?->nama_ruangan ?? 'Ruangan'),
                sprintf(
                    'Tugas untuk ruangan %s (%s) harus diselesaikan sebelum %s. Sisa waktu pengerjaan Anda kurang dari %d menit.',
                    $task->room?->nama_ruangan,
                    $task->room?->kode_ruangan,
                    $task->due_datetime->format('H:i'),
                    $reminderBeforeMinutes
                ),
                [
                    'task_id' => $task->id,
                    'room_name' => $task->room?->nama_ruangan,
                    'room_code' => $task->room?->kode_ruangan,
                    'due_datetime' => $task->due_datetime->toDateTimeString(),
                ],
                'both'
            );
        }

        $this->info("Pengingat berhasil diproses.");
        
        return self::SUCCESS;
    }
}
