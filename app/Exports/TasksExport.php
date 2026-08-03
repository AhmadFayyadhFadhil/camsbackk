<?php

namespace App\Exports;

use App\Models\Task;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TasksExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * Ambil data tugas untuk diekspor.
     */
    public function collection()
    {
        $query = Task::query()->with(['room.building', 'cs', 'shift', 'submission.verifications']);

        if (!empty($this->filters['date_from'])) {
            $query->whereDate('tanggal_task', '>=', $this->filters['date_from']);
        }

        if (!empty($this->filters['date_to'])) {
            $query->whereDate('tanggal_task', '<=', $this->filters['date_to']);
        }

        if (!empty($this->filters['building_id'])) {
            $buildingId = $this->filters['building_id'];
            $query->whereHas('room', function ($q) use ($buildingId) {
                $q->where('building_id', $buildingId);
            });
        }

        if (!empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        return $query->get()->map(function ($task, $index) {
            $submission = $task->submission;
            $approvedVerification = $submission ? $submission->verifications->where('status', 'approved')->first() : null;

            return [
                'No' => $index + 1,
                'Tanggal Tugas' => $task->tanggal_task->toDateString(),
                'Gedung' => $task->room?->building?->nama_gedung ?? '-',
                'Ruangan' => $task->room?->nama_ruangan ?? '-',
                'Petugas CS' => $task->cs?->full_name ?? 'Belum Ditugaskan',
                'Shift' => $task->shift?->nama_shift ?? '-',
                'Status' => strtoupper($task->status->value),
                'Mulai' => $submission ? $submission->submitted_at->toDateTimeString() : '-',
                'Selesai' => $approvedVerification ? $approvedVerification->verified_at->toDateTimeString() : '-',
            ];
        });
    }

    /**
     * Tentukan header ekspor.
     */
    public function headings(): array
    {
        return [
            'No',
            'Tanggal Tugas',
            'Gedung',
            'Ruangan',
            'Petugas CS',
            'Shift',
            'Status',
            'Waktu Mulai',
            'Waktu Selesai',
        ];
    }

    /**
     * Setel gaya baris judul (Header).
     */
    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => [
                    'bold' => true,
                    'color' => ['rgb' => 'FFFFFF'],
                ],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1A3C5E'],
                ]
            ],
        ];
    }
}
