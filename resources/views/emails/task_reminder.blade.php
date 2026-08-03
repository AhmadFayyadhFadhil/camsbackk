@extends('emails.layout')

@section('content')
    <h2>Pengingat Batas Waktu Tugas</h2>
    <p>Halo Petugas CS,</p>
    <p>{{ $messageBody }}</p>
    
    <div class="details-box" style="border-left-color: #d97706;">
        @if(!empty($mailData['room_name']))
            <p><strong>Ruangan:</strong> {{ $mailData['room_name'] }} ({{ $mailData['room_code'] ?? '' }})</p>
        @endif
        @if(!empty($mailData['due_datetime']))
            <p><strong>Batas Akhir (Deadline):</strong> {{ $mailData['due_datetime'] }}</p>
        @endif
    </div>

    <p>Segera lakukan pemindaian (scan) QR Code ruangan tersebut untuk memulai pengerjaan tugas sebelum melewati batas waktu.</p>
@endsection
