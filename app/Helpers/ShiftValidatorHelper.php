<?php

namespace App\Helpers;

use App\Models\Shift;
use Carbon\Carbon;

class ShiftValidatorHelper
{

    public static function isWithinShift(Shift $shift): bool
    {
        $now    = Carbon::now('Asia/Jakarta');
        $mulai  = Carbon::createFromTimeString($shift->jam_mulai,   'Asia/Jakarta');
        $selesai= Carbon::createFromTimeString($shift->jam_selesai, 'Asia/Jakarta');
        
        $bufferMinutes = (int) \App\Helpers\SettingHelper::get('buffer_shift_minutes', 30);
        $mulaiB   = $mulai->copy()->subMinutes($bufferMinutes);
        $selesaiB = $selesai->copy()->addMinutes($bufferMinutes);

        if ($shift->is_overnight) {
            // S3 (22:00-06:00): valid jika now >= 21:30 ATAU now <= 06:30
            return $now->gte($mulaiB) || $now->lte($selesaiB);
        }
        return $now->between($mulaiB, $selesaiB);
    }

    public static function getCurrentShift(): ?Shift
    {
        $shifts = Shift::where('is_active', true)->get();
        
        // 1. Cek kecocokan tepat tanpa buffer toleransi (mencegah tumpang tindih)
        foreach ($shifts as $shift) {
            $now    = Carbon::now('Asia/Jakarta');
            $mulai  = Carbon::createFromTimeString($shift->jam_mulai,   'Asia/Jakarta');
            $selesai= Carbon::createFromTimeString($shift->jam_selesai, 'Asia/Jakarta');
            
            $isMatch = false;
            if ($shift->is_overnight) {
                $isMatch = $now->gte($mulai) || $now->lte($selesai);
            } else {
                $isMatch = $now->between($mulai, $selesai);
            }
            
            if ($isMatch) {
                return $shift;
            }
        }
        
        // 2. Cek kecocokan menggunakan buffer toleransi jika tidak ada kecocokan tepat
        foreach ($shifts as $shift) {
            if (self::isWithinShift($shift)) {
                return $shift;
            }
        }
        
        return null;
    }

    public static function calculateDueDatetime(Shift $shift, string $taskDate): Carbon
    {
        $date = Carbon::parse($taskDate, 'Asia/Jakarta');
        if ($shift->is_overnight) {
            // S3 selesai jam 06:00 keesokan hari
            return $date->copy()->addDay()->setTimeFromTimeString($shift->jam_selesai);
        }
        return $date->copy()->setTimeFromTimeString($shift->jam_selesai);
    }
}
