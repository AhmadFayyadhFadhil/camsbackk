<?php

namespace App\Exports;

use App\Models\Finding;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FindingsExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /**
     * Ambil data temuan untuk diekspor.
     */
    public function collection()
    {
        $query = Finding::query()->with(['room.building', 'reporter', 'assignee']);

        if (!empty($this->filters['date_from'])) {
            $query->whereDate('created_at', '>=', $this->filters['date_from']);
        }

        if (!empty($this->filters['date_to'])) {
            $query->whereDate('created_at', '<=', $this->filters['date_to']);
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

        if (!empty($this->filters['prioritas'])) {
            $query->where('prioritas', $this->filters['prioritas']);
        }

        return $query->get()->map(function ($finding, $index) {
            $assigneeName = $finding->assigned_to_external ?? ($finding->assignee?->full_name ?? 'Belum Ditugaskan');
            
            $slaStatus = 'Dalam Batas Waktu';
            if ($finding->status?->value !== 'resolved' && $finding->deadline_perbaikan && $finding->deadline_perbaikan->isPast()) {
                $slaStatus = 'Melewati Deadline';
            }

            return [
                'No' => $index + 1,
                'Tanggal Laporan' => $finding->created_at?->toDateString() ?? '-',
                'Gedung' => $finding->room?->building?->nama_gedung ?? '-',
                'Ruangan' => $finding->room?->nama_ruangan ?? '-',
                'Deskripsi Kerusakan' => $finding->deskripsi,
                'Prioritas' => strtoupper($finding->prioritas?->value ?? 'MEDIUM'),
                'Status' => strtoupper($finding->status?->value ?? 'OPEN'),
                'Ditugaskan Kepada' => $assigneeName,
                'Tanggal Penugasan' => $finding->assigned_at ? $finding->assigned_at->toDateTimeString() : '-',
                'Batas Waktu' => $finding->deadline_perbaikan ? $finding->deadline_perbaikan->toDateString() : '-',
                'Tanggal Selesai' => $finding->resolved_at ? $finding->resolved_at->toDateTimeString() : '-',
                'Response Time (Menit)' => $finding->response_time_minutes ?? '-',
                'Status SLA' => $slaStatus,
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
            'Tanggal Laporan',
            'Gedung',
            'Ruangan',
            'Deskripsi Kerusakan',
            'Prioritas',
            'Status',
            'Ditugaskan Kepada',
            'Tanggal Penugasan',
            'Batas Waktu (Deadline)',
            'Tanggal Selesai',
            'Response Time (Menit)',
            'Status SLA',
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
                    'startColor' => ['rgb' => '8E3A1A'], // Distinct warm header style for findings
                ]
            ],
        ];
    }
}
