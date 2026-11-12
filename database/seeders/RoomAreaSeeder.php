<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\Room;
use App\Models\RoomArea;
use App\Services\QrCodeService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeder Area Ruangan.
 *
 * SUMBER DATA: file Excel "Area Kerja Office & Cleaning Service" (sheet "Area Kerja").
 *  - Nama area, penomoran area, nama ruangan, dan urutannya diambil apa adanya dari Excel
 *    (hanya spasi di awal/akhir teks yang dirapikan).
 *
 * DATA YANG TIDAK ADA DI EXCEL (placeholder development, bukan dari Excel):
 *  - Gedung   : memakai gedung dari env CAMS_SEED_BUILDING_CODE, atau gedung aktif tertua jika env kosong.
 *  - Kode     : dibuat otomatis "<PREFIX>-<nomor urut 2 digit>" (mis. OFC-03), bisa diubah dari UI.
 *  - Lantai, PIC, template checklist, GPS : dibiarkan kosong (tidak dikarang).
 *
 * Aman dijalankan berulang: tidak menghapus data, tidak menduplikasi ruangan,
 * tidak menimpa perubahan manual, dan tidak menghidupkan kembali ruangan yang sudah di-soft delete.
 *
 * Jalankan: php artisan db:seed --class=RoomAreaSeeder
 */
class RoomAreaSeeder extends Seeder
{
    private const AREAS = [
        [
            'nomor' => '1',
            'nama_area' => 'LOBBY',
            'kode_prefix' => 'LBY',
            'rooms' => [
            'Lobby',
            'VVIP Lobby',
            'Lift',
            'Tangga Lobby',
            ],
        ],
        [
            'nomor' => '1.2',
            'nama_area' => 'OFFICE',
            'kode_prefix' => 'OFC',
            'rooms' => [
            'Ruang Kasir',
            'Ruang IT Dept. Head',
            'Ruang Dokumen FA',
            'Ruang FA Dept. Head',
            'Ruang Payroll',
            'Cubicle FA',
            'Cubicle IT',
            'Cubicle HRD & GA',
            'Ruang Supply Chain Dept. Head',
            'Ruang Secretary',
            'Cubicle Supply Chain',
            'Ruang HRD & GA Division Head',
            'Ruang Asst. TO PGM',
            'Ruang Factory Director',
            'Ruang Plan General Manager',
            'Cubicle Teknik',
            'Ruang Dokumen Teknik',
            'Ruang TD. Dept. Head',
            'Ruang IARM Division Head',
            'Ruang QA Dept. Head',
            'Ruang Dokumen QA',
            'Ruang Staff QA',
            'Ruang Pantry',
            'Cubicle QA',
            ],
        ],
        [
            'nomor' => '1.3',
            'nama_area' => 'RUANG MEETING',
            'kode_prefix' => 'MTG',
            'rooms' => [
            'Wida 12',
            'Wida 3',
            'Wida 4',
            'BOD',
            'R. Training',
            ],
        ],
        [
            'nomor' => '1.4',
            'nama_area' => 'TOILET',
            'kode_prefix' => 'TLT',
            'rooms' => [
            'Toilet Tamu',
            'Toilet Karyawan',
            ],
        ],
    ];

    public function run(): void
    {
        $building = $this->resolveBuilding();

        if (!$building) {
            $this->command?->warn('Seeder dibatalkan: belum ada gedung aktif. Buat gedung lebih dulu (Master Data > Kelola Gedung), lalu jalankan ulang.');
            return;
        }

        $this->command?->info("Gedung yang dipakai (placeholder): {$building->nama_gedung} [{$building->kode_gedung}]");

        $qrService = resolve(QrCodeService::class);
        $stat = ['area' => 0, 'dibuat' => 0, 'ditautkan' => 0, 'sudah_sesuai' => 0, 'dilewati' => 0];

        DB::transaction(function () use ($building, $qrService, &$stat) {
            foreach (self::AREAS as $areaIndex => $areaData) {
                $area = RoomArea::updateOrCreate(
                    ['nama_area' => $areaData['nama_area']],
                    ['nomor' => $areaData['nomor'], 'urutan' => $areaIndex + 1, 'is_active' => true]
                );
                $stat['area']++;

                foreach ($areaData['rooms'] as $i => $roomName) {
                    $order = $i + 1;
                    $kode = sprintf('%s-%02d', $areaData['kode_prefix'], $order);

                    // Cari ruangan yang sudah ada (termasuk yang di-soft delete) agar tidak terjadi duplikasi.
                    $existing = Room::withTrashed()
                        ->where('building_id', $building->id)
                        ->where('kode_ruangan', $kode)
                        ->first();

                    if (!$existing) {
                        $existing = Room::where('building_id', $building->id)
                            ->whereRaw('LOWER(TRIM(nama_ruangan)) = ?', [mb_strtolower($roomName)])
                            ->first();
                    }

                    if ($existing) {
                        $sameName = mb_strtolower(trim($existing->nama_ruangan)) === mb_strtolower($roomName);

                        if ($existing->trashed() || !$sameName) {
                            $stat['dilewati']++;
                            $this->command?->warn("Dilewati: {$roomName} ({$kode}) - kode/nama sudah dipakai ruangan lain atau ruangan sudah dihapus.");
                            continue;
                        }

                        if ($existing->room_area_id === null) {
                            $existing->update(['room_area_id' => $area->id, 'urutan_area' => $order]);
                            $stat['ditautkan']++;
                        } else {
                            $stat['sudah_sesuai']++;
                        }
                        continue;
                    }

                    $room = Room::create([
                        'building_id' => $building->id,
                        'room_area_id' => $area->id,
                        'urutan_area' => $order,
                        'kode_ruangan' => $kode,
                        'nama_ruangan' => $roomName,
                        'lantai' => null,
                        'pic_user_id' => null,
                        'qr_code_token' => (string) Str::uuid(),
                        'is_active' => true,
                    ]);

                    $room->update([
                        'qr_code_image' => $qrService->generate($room->id, $room->qr_code_token, $room->building_id),
                    ]);
                    $stat['dibuat']++;
                }
            }
        });

        $this->command?->info(sprintf(
            'Selesai. Area: %d | Ruangan dibuat: %d | ditautkan ke area: %d | sudah sesuai: %d | dilewati: %d',
            $stat['area'], $stat['dibuat'], $stat['ditautkan'], $stat['sudah_sesuai'], $stat['dilewati']
        ));
    }

    private function resolveBuilding(): ?Building
    {
        $code = env('CAMS_SEED_BUILDING_CODE');

        if ($code) {
            return Building::where('kode_gedung', $code)->first();
        }

        return Building::where('is_active', true)
            ->orderBy('created_at')
            ->orderBy('kode_gedung')
            ->first();
    }
}
