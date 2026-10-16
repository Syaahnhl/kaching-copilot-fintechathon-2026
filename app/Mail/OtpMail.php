<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public $otpCode;
    public $userName;

    public function __construct($otpCode, $userName)
    {
        $this->otpCode = $otpCode;
        $this->userName = $userName;
    }

    public function build()
    {
        return $this->subject('Kode Verifikasi (OTP) Kaching POS')
            ->html("
                        <h2>Halo, {$this->userName}!</h2>
                        <p>Terima kasih telah mendaftar di Kaching POS. Berikut adalah kode verifikasi OTP Anda:</p>
                        <h1 style='color: #3B82F6; letter-spacing: 5px;'>{$this->otpCode}</h1>
                        <p>Kode ini bersifat rahasia. Jangan berikan kepada siapapun.</p>
                        <br>
                        <p>Salam hangat,<br>Tim Kaching POS</p>
                    ");
    }
}
