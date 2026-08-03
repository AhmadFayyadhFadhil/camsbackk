@extends('emails.layout')

@section('content')
    <h2>Eskalasi Verifikasi Laporan Kebersihan</h2>
    <p>Halo Supervisor,</p>
    <p style="color: #ea580c; font-weight: bold;">{{ $messageBody }}</p>
    
    <div class="details-box" style="border-left-color: #ea580c;">
        @if(!empty($mailData['room_name']))
            <p><strong>Ruangan:</strong> {{ $mailData['room_name'] }} ({{ $mailData['room_code'] ?? '' }})</p>
        @endif
        @if(!empty($mailData['cs_name']))
            <p><strong>Dikerjakan Oleh:</strong> {{ $mailData['cs_name'] }}</p>
        @endif
        @if(!empty($mailData['submission_time']))
            <p><strong>Waktu Kirim Laporan:</strong> {{ $mailData['submission_time'] }}</p>
        @endif
    </div>

    <p>Mohon bantuan Supervisor untuk menghubungi PIC terkait atau mengambil alih proses persetujuan (verification) melalui sistem CAMS.</p>
@endsection
