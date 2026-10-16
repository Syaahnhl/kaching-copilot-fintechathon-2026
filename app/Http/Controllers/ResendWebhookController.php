<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Resend delivery webhooks (bounce / complaint / delivered).
 * Docs: https://resend.com/docs/dashboard/webhooks/event-types
 *
 * Auth: Svix-style headers (svix-id, svix-timestamp, svix-signature) when
 * RESEND_WEBHOOK_SECRET is set; otherwise reject in production.
 */
class ResendWebhookController extends Controller
{
    public function handle(Request $request)
    {
        if (!$this->verifySignature($request)) {
            Log::warning('Resend webhook: invalid signature', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = $request->all();
        $type = (string) ($payload['type'] ?? $payload['event'] ?? '');
        $data = $payload['data'] ?? [];

        $email = $this->extractEmail($data);
        if ($email === null) {
            return response()->json(['status' => 'ignored', 'reason' => 'no_email'], 200);
        }

        $email = EmailAddress::normalize($email);
        $user = User::where('email', $email)->first();

        Log::info('Resend webhook', [
            'type' => $type,
            'email_hash' => hash('sha256', $email),
            'user_id' => $user?->id,
        ]);

        // Hard bounce / failed / complained → mark bounced (block login)
        $bounceTypes = [
            'email.bounced',
            'email.failed',
            'email.complained',
            'email.suppressed',
        ];

        if (in_array($type, $bounceTypes, true) && $user) {
            // Only auto-bounce pending/unverified; don't nuke long-active merchants
            // on a single complaint without review — still mark bounced for pending.
            $status = (string) ($user->email_status ?? '');
            if (in_array($status, ['pending', 'bounced', ''], true) || $user->email_verified_at === null) {
                $user->email_status = 'bounced';
                $user->save();
                Log::warning('User email marked bounced via Resend', [
                    'user_id' => $user->id,
                    'type' => $type,
                ]);
            } else {
                // Active verified merchant: log only (ops review)
                Log::warning('Resend bounce on verified user (no auto-block)', [
                    'user_id' => $user->id,
                    'type' => $type,
                    'email_status' => $status,
                ]);
            }
        }

        if ($type === 'email.delivered' && $user && ($user->email_status ?? '') === 'pending') {
            // Delivery OK — stay pending until OTP; no status change needed
            Log::info('Resend delivered to pending user', ['user_id' => $user->id]);
        }

        return response()->json(['status' => 'ok'], 200);
    }

    private function extractEmail(array $data): ?string
    {
        // Resend shapes vary: to may be string or list
        $to = $data['to'] ?? $data['email'] ?? null;
        if (is_array($to)) {
            $to = $to[0] ?? null;
            if (is_array($to)) {
                $to = $to['email'] ?? null;
            }
        }
        if (is_string($to) && str_contains($to, '@')) {
            return $to;
        }

        return null;
    }

    private function verifySignature(Request $request): bool
    {
        $secret = (string) config('services.resend.webhook_secret', env('RESEND_WEBHOOK_SECRET', ''));

        // Local/dev without secret: allow only non-production
        if ($secret === '') {
            return !app()->environment('production');
        }

        // Svix signing (Resend default): whsec_...
        $msgId = $request->header('svix-id') ?? $request->header('webhook-id');
        $timestamp = $request->header('svix-timestamp') ?? $request->header('webhook-timestamp');
        $signatureHeader = $request->header('svix-signature') ?? $request->header('webhook-signature');

        if (!$msgId || !$timestamp || !$signatureHeader) {
            // Fallback: shared bearer secret in query/header for simple setups
            $bearer = $request->bearerToken()
                ?? $request->header('X-Resend-Webhook-Secret')
                ?? $request->query('secret');

            return is_string($bearer) && hash_equals($secret, $bearer);
        }

        // Reject stale timestamps (>5 min)
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $body = $request->getContent();
        $toSign = $msgId . '.' . $timestamp . '.' . $body;

        // secret may be whsec_ base64
        $key = $secret;
        if (str_starts_with($secret, 'whsec_')) {
            $key = base64_decode(substr($secret, 6), true) ?: $secret;
        }

        $expected = base64_encode(hash_hmac('sha256', $toSign, $key, true));

        // Header format: "v1,sig1 v1,sig2"
        foreach (preg_split('/\s+/', trim($signatureHeader)) as $part) {
            $sig = $part;
            if (str_contains($part, ',')) {
                [, $sig] = explode(',', $part, 2);
            }
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
