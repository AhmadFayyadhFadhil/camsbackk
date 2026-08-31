<?php

namespace App\Services;

use App\Models\Schedule;
use App\Models\Task;
use App\Models\CsAssignment;
use App\Models\Room;
use App\Models\ChecklistItem;
use App\Enums\FrequencyEnum;
use App\Helpers\ShiftValidatorHelper;
use Carbon\Carbon;

class TaskGeneratorService
{
    /**
     * Membuat data task harian berdasarkan schedule aktif dan penugasan CS.
     * Secara dinamis mendukung checklist template pada ruangan.
     *
     * @param Carbon $targetDate
     * @return array Summary of generated and skipped tasks
     */
    public function generateForDate(Carbon $targetDate): array
    {
        $generated  = 0;
        $skipped    = 0;
        $dayOfWeek  = (int) $targetDate->format("w"); // 0=Minggu s/d 6=Sabtu
        $dayOfMonth = (int) $targetDate->format("j"); // 1-31

        $schedules = Schedule::with(['room.building.shifts', 'shift'])
            ->where('is_active', true)
            ->whereHas('room', function ($q) {
                $q->where('is_active', true)->whereHas('building', function ($b) {
                    $b->where('is_active', true);
                });
            })
            ->get();

        foreach ($schedules as $schedule) {
            if (!$schedule->room || !$schedule->room->building) {
                continue;
            }

            // Validasi: Shift dari jadwal ini harus benar-benar aktif di gedung ruangan tersebut
            $activeBuildingShiftIds = $schedule->room->building->shifts->pluck('id')->toArray();
            if (!in_array($schedule->shift_id, $activeBuildingShiftIds)) {
                $schedule->update(['is_active' => false]);
                continue;
            }

            // 1. Filter frekuensi
            if ($schedule->frekuensi === FrequencyEnum::MINGGUAN && $schedule->hari_minggu !== $dayOfWeek) {
                continue;
            }
            if ($schedule->frekuensi === FrequencyEnum::BULANAN && $schedule->tanggal_bulan !== $dayOfMonth) {
                continue;
            }

            // 2. Idempotent check — jangan buat duplikat
            $exists = Task::where('schedule_id', $schedule->id)
                ->where('tanggal_task', $targetDate->toDateString())
                ->exists();
            if ($exists) {
                $skipped++;
                continue;
            }

            // 3. Cari CS yang bertugas
            // Prioritas:
            // - Cari penugasan spesifik untuk shift tersebut
            // - Jika tidak ada, cari penugasan umum (shift_id NULL) di gedung tersebut
            $assignment = CsAssignment::where('building_id', $schedule->room->building_id)
                ->where('tanggal_mulai', '<=', $targetDate->toDateString())
                ->where(function ($q) use ($targetDate) {
                    $q->whereNull("tanggal_selesai")
                      ->orWhere("tanggal_selesai", ">=", $targetDate->toDateString());
                })
                ->where(function($q) use ($schedule) {
                    $q->where('shift_id', $schedule->shift_id)
                      ->orWhereNull('shift_id');
                })
                ->orderByRaw('CASE WHEN shift_id IS NULL THEN 1 ELSE 0 END ASC')
                ->first();

            // 4. Hitung due_datetime (handle overnight S3)
            $due = ShiftValidatorHelper::calculateDueDatetime($schedule->shift, $targetDate->toDateString());

            // 5. Buat task
            Task::create([
                'schedule_id'        => $schedule->id,
                'room_id'            => $schedule->room_id,
                'cs_user_id'         => $assignment?->cs_user_id,
                'shift_id'           => $schedule->shift_id,
                'tanggal_task'       => $targetDate->toDateString(),
                'target_jam_mulai'   => $schedule->target_jam_mulai,
                'target_jam_selesai' => $schedule->target_jam_selesai,
                'status'             => \App\Enums\TaskStatusEnum::PENDING,
                'due_datetime'       => $due,
            ]);
            
            $generated++;
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }
}
