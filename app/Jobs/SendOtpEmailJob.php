<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\Auth\OtpService;
use App\Services\Email\ResendMailService;
use App\DTOs\EmailPayload;
use App\Enums\EmailType;

class SendOtpEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public array $backoff = [10, 30, 60];

    protected string $email;
    protected string $type;
    protected string $code;

    /**
     * Create a new job instance.
     */
    public function __construct(string $email, string $type, string $code)
    {
        $this->email = $email;
        $this->type = $type;
        $this->code = $code;
    }

    /**
     * Execute the job.
     */
    public function handle(ResendMailService $resendMailService): void
    {
        $code = $this->code;

        $subject = 'Your Kaching OTP Code';
        $htmlContent = "<h1>Your Kaching Security Code is: {$code}</h1>";

        if ($this->type === EmailType::PASSWORD_RESET->value) {
            $subject = 'Password Reset Security Code';
            $htmlContent = "<h1>Password Reset</h1><p>Your Kaching password reset code is: <strong>{$code}</strong></p><p>This code will expire in 5 minutes.</p>";
        } elseif ($this->type === EmailType::OTP_VERIFICATION->value) {
            $subject = 'Your Kaching OTP Code';
            $htmlContent = "<h1>Welcome to Kaching</h1><p>Your Kaching security code is: <strong>{$code}</strong></p><p>This code will expire in 5 minutes.</p>";
        }

        // Construct the EmailPayload
        $payload = new EmailPayload(
            to: $this->email,
            subject: $subject,
            htmlContent: $htmlContent,
            type: EmailType::from($this->type)
        );

        // Call the mail service to send it
        $resendMailService->send($payload);
    }
}
