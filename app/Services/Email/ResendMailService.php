<?php

namespace App\Services\Email;

use App\DTOs\EmailPayload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Resend\Laravel\Facades\Resend;
use Exception;

class ResendMailService
{
    /**
     * Send an email using Resend API.
     *
     * @param EmailPayload $payload
     * @return bool
     */
    public function send(EmailPayload $payload): bool
    {
        try {
            $response = Resend::emails()->send([
                'from' => $payload->from ?? config('mail.from.address', 'no-reply@kaching.id'),
                'to' => [$payload->to],
                'subject' => $payload->subject,
                'html' => $payload->htmlContent,
            ]);

            // Log success with hashed target email for privacy
            $hashedEmail = Hash::make($payload->to);
            Log::info("Email sent successfully via Resend", [
                'type' => $payload->type->value,
                'to_hashed' => $hashedEmail,
                'resend_id' => $response->id ?? null,
            ]);

            return true;
        } catch (Exception $e) {
            // Log critical errors on failure
            Log::critical("Failed to send email via Resend", [
                'type' => $payload->type->value,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Propagate exception to Laravel Queue for retry lifecycle
            throw new Exception("Resend API Delivery Failed: " . $e->getMessage());
        }
    }
}
