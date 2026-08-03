@extends('emails.layout')

@section('content')
    <h2>Selamat Datang di CAMS</h2>
    <p>Halo,</p>
    <p>{{ $messageBody }}</p>
    
    <div class="details-box">
        @if(!empty($mailData['email']))
            <p><strong>Username/Email Anda:</strong> {{ $mailData['email'] }}</p>
        @endif
    </div>

    <p>Akun Anda kini telah aktif di sistem CAMS (Cleaning Activity Monitoring System) PT Widatra Bhakti. Silakan hubungi Administrator jika Anda mengalami kesulitan dalam masuk ke aplikasi.</p>
@endsection
