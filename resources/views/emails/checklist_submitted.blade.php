@extends('emails.layout')

@section('content')
    <h2>Laporan Kebersihan Menunggu Verifikasi</h2>
    <p>Halo PIC,</p>
    <p>{{ $messageBody }}</p>
    
    <div class="details-box">
        @if(!empty($mailData['room_name']))
            <p><strong>Ruangan:</strong> {{ $mailData['room_name'] }} ({{ $mailData['room_code'] ?? '' }})</p>
        @endif
        @if(!empty($mailData['cs_name']))
            <p><strong>Petugas CS:</strong> {{ $mailData['cs_name'] }}</p>
        @endif
        @if(!empty($mailData['submission_time']))
            <p><strong>Waktu Penyerahan:</strong> {{ $mailData['submission_time'] }}</p>
        @endif
    </div>

    <p>Silakan masuk ke aplikasi web/mobile CAMS untuk meninjau dan memverifikasi laporan pengerjaan tersebut.</p>
@endsection
