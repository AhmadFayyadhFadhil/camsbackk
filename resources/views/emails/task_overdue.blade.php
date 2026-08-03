@extends('emails.layout')

@section('content')
    <h2>Pemberitahuan Tugas Kedaluwarsa (Overdue)</h2>
    <p>Halo Supervisor,</p>
    <p style="color: #b91c1c; font-weight: bold;">{{ $messageBody }}</p>
    
    <div class="details-box" style="border-left-color: #b91c1c;">
        @if(!empty($mailData['room_name']))
            <p><strong>Ruangan:</strong> {{ $mailData['room_name'] }} ({{ $mailData['room_code'] ?? '' }})</p>
        @endif
        @if(!empty($mailData['cs_name']))
            <p><strong>Ditugaskan Kepada:</strong> {{ $mailData['cs_name'] }}</p>
        @endif
        @if(!empty($mailData['due_datetime']))
            <p><strong>Tenggat Waktu:</strong> {{ $mailData['due_datetime'] }}</p>
        @endif
    </div>

    <p>Tugas di atas telah otomatis diubah statusnya menjadi OVERDUE oleh sistem karena melewati batas pengerjaan.</p>
@endsection
