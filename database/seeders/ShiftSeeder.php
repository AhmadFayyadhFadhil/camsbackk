<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Shift;

class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            [
                'kode_shift' => 'S1',
                'nama_shift' => 'Shift 1',
                'jam_mulai' => '06:00:00',
                'jam_selesai' => '14:00:00',
                'is_overnight' => false,
            ],
            [
                'kode_shift' => 'S2',
                'nama_shift' => 'Shift 2',
                'jam_mulai' => '14:00:00',
                'jam_selesai' => '22:00:00',
                'is_overnight' => false,
            ],
            [
                'kode_shift' => 'S3',
                'nama_shift' => 'Shift 3',
                'jam_mulai' => '22:00:00',
                'jam_selesai' => '06:00:00',
                'is_overnight' => true,
            ],
            [
                'kode_shift' => 'SN',
                'nama_shift' => 'Shift Normal',
                'jam_mulai' => '07:30:00',
                'jam_selesai' => '16:30:00',
                'is_overnight' => false,
            ],
        ];

        foreach ($shifts as $shift) {
            Shift::firstOrCreate(
                ['kode_shift' => $shift['kode_shift']],
                [
                    'nama_shift' => $shift['nama_shift'],
                    'jam_mulai' => $shift['jam_mulai'],
                    'jam_selesai' => $shift['jam_selesai'],
                    'is_overnight' => $shift['is_overnight'],
                    'is_active' => true,
                ]
            );
        }
    }
}
