@extends('emails.layout')

@section('content')
    <h2>Laporan Kebersihan Disetujui</h2>
    <p>Halo Petugas CS,</p>
    <p>{{ $messageBody }}</p>
    
    <div class="details-box">
        @if(!empty($mailData['room_name']))
            <p><strong>Ruangan:</strong> {{ $mailData['room_name'] }}</p>
        @endif
        @if(!empty($mailData['verified_by']))
            <p><strong>Diverifikasi Oleh:</strong> {{ $mailData['verified_by'] }}</p>
        @endif
        @if(!empty($mailData['notes']))
            <p><strong>Catatan PIC:</strong> "{{ $mailData['notes'] }}"</p>
        @endif
    </div>

    <p>Terima kasih atas dedikasi dan kerja keras Anda dalam menjaga kebersihan area kerja.</p>
@endsection
