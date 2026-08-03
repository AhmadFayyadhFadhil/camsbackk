<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Kebersihan CAMS</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 11px;
            color: #333333;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #005691;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #005691;
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
            font-size: 10px;
        }
        .meta-info td {
            padding: 2px 0;
        }
        .report-title {
            font-size: 14px;
            font-weight: bold;
            color: #1a3c5e;
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
            background-color: #1a3c5e;
            color: #ffffff;
            font-weight: bold;
            text-align: left;
            padding: 6px 8px;
            border: 1px solid #dddddd;
            text-transform: uppercase;
            font-size: 9px;
        }
        table.data-table td {
            padding: 6px 8px;
            border: 1px solid #dddddd;
            font-size: 9px;
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
    </style>
</head>
<body>

    <div class="header">
        <h1>CAMS — PT Widatra Bhakti</h1>
        <p>Cleaning Activity Monitoring System (Otsuka Group) — Plant Pandaan</p>
    </div>

    <div class="report-title">Laporan Kebersihan Ruangan</div>

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
    </table>

    <table class="data-table">
        <thead>
            <tr>
                <th width="4%">No</th>
                <th width="12%">Tanggal</th>
                <th width="15%">Gedung</th>
                <th width="20%">Ruangan</th>
                <th width="15%">Petugas CS</th>
                <th width="10%">Shift</th>
                <th width="10%">Status</th>
                <th width="14%">Pengerjaan</th>
            </tr>
        </thead>
        <tbody>
            @forelse($tasks as $index => $task)
                @php
                    $submission = $task->submission;
                    $approvedVerification = $submission ? $submission->verifications->where('status', 'approved')->first() : null;
                @endphp
                <tr>
                    <td align="center">{{ $index + 1 }}</td>
                    <td>{{ $task->tanggal_task->toDateString() }}</td>
                    <td>{{ $task->room?->building?->nama_gedung ?? '-' }}</td>
                    <td>{{ $task->room?->nama_ruangan ?? '-' }}</td>
                    <td>{{ $task->cs?->full_name ?? 'Belum Ditugaskan' }}</td>
                    <td>{{ $task->shift?->nama_shift ?? '-' }}</td>
                    <td align="center"><strong>{{ strtoupper($task->status->value) }}</strong></td>
                    <td>
                        Mulai: {{ $submission ? $submission->submitted_at->format('H:i') : '-' }}<br>
                        Selesai: {{ $approvedVerification ? $approvedVerification->verified_at->format('H:i') : '-' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" align="center">Tidak ada data laporan yang sesuai dengan filter.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        <p>Laporan ini dibuat otomatis oleh sistem CAMS PT Widatra Bhakti. Dokumen resmi tanpa tanda tangan basah.</p>
    </div>

</body>
</html>
