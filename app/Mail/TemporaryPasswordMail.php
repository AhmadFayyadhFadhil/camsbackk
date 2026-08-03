<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TemporaryPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $temporaryPassword;
    public string $userName;

    /**
     * Create a new message instance.
     */
    public function __construct(string $userName, string $temporaryPassword)
    {
        $this->userName = $userName;
        $this->temporaryPassword = $temporaryPassword;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'CAMS - Password Sementara Anda',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: sprintf(
                "<p>Halo %s,</p><p>Password akun CAMS Anda telah di-reset oleh Admin.</p><p>Password sementara Anda adalah: <strong>%s</strong></p><p>Harap segera login dan ganti password Anda demi keamanan.</p>",
                e($this->userName),
                e($this->temporaryPassword)
            )
        );
    }
}
