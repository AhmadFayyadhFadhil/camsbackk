<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Temuan Kerusakan CAMS</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 10px;
            color: #333333;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #8E3A1A;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #8E3A1A;
            font-size: 18px;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .header p {
            margin: 3px 0 0 0;
            font-size: 10px;
            color: #555555;
        }
        .meta-info {
            width: 100%;
            margin-bottom: 15px;
            font-size: 9px;
        }
        .meta-info td {
            padding: 2px 0;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #8E3A1A;
            text-align: center;
            margin-bottom: 15px;
            text-transform: uppercase;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        table.data-table th {
            background-color: #8E3A1A;
            color: #ffffff;
            font-weight: bold;
            text-align: left;
            padding: 5px 6px;
            border: 1px solid #dddddd;
            text-transform: uppercase;
            font-size: 8px;
        }
        table.data-table td {
            padding: 5px 6px;
            border: 1px solid #dddddd;
            font-size: 8px;
            vertical-align: top;
        }
        table.data-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 8px;
            color: #777777;
            border-top: 1px solid #dddddd;
            padding-top: 5px;
        }
        .badge {
            font-size: 7px;
            font-weight: bold;
            padding: 1px 4px;
            border-radius: 3px;
            display: inline-block;
        }
        .badge-danger { background-color: #fce8e6; color: #c5221f; }
        .badge-success { background-color: #e6f4ea; color: #137333; }
        .badge-warning { background-color: #fef7e0; color: #b06000; }
        .badge-info { background-color: #e8f0fe; color: #1a73e8; }
    </style>
</head>
<body>

    <div class="header">
        <h1>CAMS — PT Widatra Bhakti</h1>
        <p>Cleaning Activity Monitoring System (Otsuka Group) — Plant Pandaan</p>
    </div>

    <div class="report-title">Laporan Temuan Kerusakan Inventaris & SLA</div>

    <table class="meta-info">
        <tr>
            <td width="15%"><strong>Rentang Waktu:</strong></td>
            <td width="35%">{{ $filters['date_from'] ?? '-' }} s/d {{ $filters['date_to'] ?? '-' }}</td>
            <td width="15%"><strong>Tanggal Cetak:</strong></td>
            <td width="35%">{{ now()->toDateTimeString() }}</td>
        </tr>
        <tr>
            <td><strong>Filter Gedung:</strong></td>
            <td>{{ $building_name ?? 'Semua Gedung' }}</td>
            <td><strong>Filter Status:</strong></td>
            <td>{{ !empty($filters['status']) ? strtoupper($filters['status']) : 'Semua Status' }}</td>
        </tr>
        <tr>
            <td><strong>Filter Prioritas:</strong></td>
            <td>{{ !empty($filters['prioritas']) ? strtoupper($filters['prioritas']) : 'Semua Prioritas' }}</td>
            <td></td>
            <td></td>
        </tr>
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th width="3%">No</th>
                <th width="10%">Tanggal Lapor</th>
                <th width="12%">Gedung</th>
                <th width="12%">Ruangan</th>
                <th width="20%">Deskripsi Kerusakan</th>
                <th width="8%">Prioritas</th>
                <th width="8%">Status</th>
                <th width="12%">Petugas / SLA</th>
                <th width="15%">Penyelesaian</th>
            </tr>
        </thead>
        <tbody>
            @forelse($findings as $index => $f)
                @php
                    $assigneeName = $f->assigned_to_external ?? ($f->assignee?->full_name ?? 'Belum Ditugaskan');
                    $isOverdue = $f->deadline_perbaikan && $f->deadline_perbaikan->isPast() && $f->status?->value !== 'resolved';
                @endphp
                <tr>
                    <td align="center">{{ $index + 1 }}</td>
                    <td>{{ $f->created_at?->toDateString() ?? '-' }}</td>
                    <td>{{ $f->room?->building?->nama_gedung ?? '-' }}</td>
                    <td>{{ $f->room?->nama_ruangan ?? '-' }}</td>
                    <td>{{ $f->deskripsi }}</td>
                    <td align="center">
                        <span class="badge {{ $f->prioritas?->value === 'high' ? 'badge-danger' : ($f->prioritas?->value === 'medium' ? 'badge-warning' : 'badge-info') }}">
                            {{ strtoupper($f->prioritas?->value ?? 'MEDIUM') }}
                        </span>
                    </td>
                    <td align="center">
                        <span class="badge {{ $f->status?->value === 'resolved' ? 'badge-success' : ($f->status?->value === 'in_progress' ? 'badge-warning' : 'badge-info') }}">
                            {{ strtoupper($f->status?->value ?? 'OPEN') }}
                        </span>
                    </td>
                    <td>
                        <strong>{{ $assigneeName }}</strong><br>
                        @if($f->deadline_perbaikan)
                            <span style="font-size: 7px; color: #555;">Deadline: {{ $f->deadline_perbaikan->toDateString() }}</span><br>
                            @if($f->status?->value !== 'resolved')
                                <span class="badge {{ $isOverdue ? 'badge-danger' : 'badge-success' }}" style="font-size: 6px;">
                                    {{ $isOverdue ? 'Overdue' : 'On SLA' }}
                                </span>
                            @endif
                        @endif
                    </td>
                    <td>
                        @if($f->status?->value === 'resolved')
                            Selesai: {{ $f->resolved_at ? $f->resolved_at->toDateTimeString() : '-' }}<br>
                            @if($f->response_time_minutes !== null)
                                @php
                                    $min = $f->response_time_minutes;
                                    $formatted = $min . ' Menit';
                                    if ($min >= 60) {
                                        $h = floor($min / 60);
                                        $m = $min % 60;
                                        $formatted = $h . ' Jam ' . $m . ' Menit';
                                    }
                                @endphp
                                <span style="font-size: 7px; color: #137333; font-weight: bold;">Durasi: {{ $formatted }}</span>
                            @endif
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" align="center">Tidak ada data temuan kerusakan yang sesuai dengan filter.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>Laporan ini dibuat otomatis oleh sistem CAMS PT Widatra Bhakti. Dokumen resmi tanpa tanda tangan basah.</p>
    </div>

</body>
</html>
