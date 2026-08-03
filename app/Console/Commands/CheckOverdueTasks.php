<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Task;
use App\Models\User;
use App\Enums\TaskStatusEnum;
use App\Enums\RoleEnum;
use App\Services\NotificationService;

class CheckOverdueTasks extends Command
{
    protected $signature = 'cams:check-overdue';
    protected $description = 'Otomatis mengubah status task menjadi overdue jika melewati due_datetime dan mengirim notifikasi ke Supervisor';

    public function handle()
    {
        $overdueTasks = Task::whereIn('status', [TaskStatusEnum::PENDING, TaskStatusEnum::IN_PROGRESS])
            ->where('due_datetime', '<', now('Asia/Jakarta'))
            ->with(['room', 'cs'])
            ->get();

        if ($overdueTasks->isEmpty()) {
            $this->info("Tidak ada tugas overdue saat ini.");
            return self::SUCCESS;
        }

        $this->info("Menemukan " . $overdueTasks->count() . " tugas overdue. Memproses...");

        // Ambil supervisor
        $supervisors = User::whereHas('roles', function($q) {
            $q->where('name', RoleEnum::SUPERVISOR->value);
        })->where('is_active', true)->get();

        foreach ($overdueTasks as $task) {
            $task->update(['status' => TaskStatusEnum::OVERDUE]);

            foreach ($supervisors as $supervisor) {
                NotificationService::send(
                    $supervisor->id,
                    'TASK_OVERDUE',
                    'Tugas Overdue: ' . ($task->room?->nama_ruangan ?? 'Ruangan'),
                    sprintf(
                        'Tugas untuk ruangan %s (%s) yang ditugaskan kepada %s telah melewati batas waktu (%s) dan berstatus OVERDUE.',
                        $task->room?->nama_ruangan,
                        $task->room?->kode_ruangan,
                        $task->cs?->full_name ?? 'Unassigned',
                        $task->due_datetime->format('Y-m-d H:i')
                    ),
                    [
                        'task_id' => $task->id,
                        'room_name' => $task->room?->nama_ruangan,
                        'room_code' => $task->room?->kode_ruangan,
                        'cs_name' => $task->cs?->full_name ?? 'Unassigned',
                        'due_datetime' => $task->due_datetime->toDateTimeString(),
                    ],
                    'both'
                );
            }
        }

        $this->info("Status tugas berhasil diperbarui ke overdue dan notifikasi telah dikirim.");
        
        return self::SUCCESS;
    }
}
