@extends('emails.layout')

@section('content')
    <h2>Laporan Kebersihan Perlu Perbaikan</h2>
    <p>Halo Petugas CS,</p>
    <p style="color: #b91c1c; font-weight: bold;">{{ $messageBody }}</p>
    
    <div class="details-box" style="border-left-color: #b91c1c;">
        @if(!empty($mailData['room_name']))
            <p><strong>Ruangan:</strong> {{ $mailData['room_name'] }}</p>
        @endif
        @if(!empty($mailData['verified_by']))
            <p><strong>Ditolak Oleh:</strong> {{ $mailData['verified_by'] }}</p>
        @endif
        @if(!empty($mailData['notes']))
            <p><strong>Catatan Perbaikan:</strong> "{{ $mailData['notes'] }}"</p>
        @endif
    </div>

    <p>Silakan lakukan pengerjaan ulang pada area tersebut sesuai instruksi di atas, dan kirimkan kembali laporan kebersihan yang telah diperbaiki.</p>
@endsection
