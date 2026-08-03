@extends('emails.layout')

@section('content')
    <h2>Temuan Masalah Baru Dilaporkan</h2>
    <p>Halo,</p>
    <p>{{ $messageBody }}</p>
    
    <div class="details-box" style="border-left-color: #ef4444;">
        @if(!empty($mailData['room_name']))
            <p><strong>Ruangan:</strong> {{ $mailData['room_name'] }} ({{ $mailData['room_code'] ?? '' }})</p>
        @endif
        @if(!empty($mailData['reporter_name']))
            <p><strong>Dilaporkan Oleh:</strong> {{ $mailData['reporter_name'] }}</p>
        @endif
        @if(!empty($mailData['priority']))
            <p><strong>Prioritas:</strong> <span style="text-transform: uppercase; font-weight: bold; color: #ef4444;">{{ $mailData['priority'] }}</span></p>
        @endif
        @if(!empty($mailData['description']))
            <p><strong>Deskripsi Masalah:</strong> "{{ $mailData['description'] }}"</p>
        @endif
    </div>

    <p>Silakan tinjau modul Temuan Masalah (Findings) di sistem CAMS untuk menindaklanjuti perbaikan fasilitas tersebut.</p>
@endsection
