@extends('emails.layout')

@section('content')
    <h2>Reset Password Akun CAMS</h2>
    <p>Halo,</p>
    <p>{{ $messageBody }}</p>
    
    <div class="details-box">
        @if(!empty($mailData['temporary_password']))
            <p><strong>Password Sementara:</strong> <span style="font-family: monospace; font-size: 16px; background-color: #e2e8f0; padding: 2px 6px; border-radius: 3px;">{{ $mailData['temporary_password'] }}</span></p>
        @endif
    </div>

    <p style="color: #ea580c; font-weight: bold;">PENTING: Segera masuk ke sistem CAMS menggunakan password sementara di atas, lalu ubah password Anda di halaman profil demi alasan keamanan.</p>
@endsection
