<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\Auth\OtpService;
use App\Jobs\SendOtpEmailJob;
use App\Enums\EmailType;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    protected OtpService $otpService;

    public function __construct(OtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    /**
     * Send OTP to email.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->input('email');
        $type = EmailType::OTP_VERIFICATION->value;

        // Generate OTP BEFORE dispatching the job (Immutable Payload Strategy)
        $code = $this->otpService->generate($email, $type);

        // Dispatch background job with immutable OTP
        SendOtpEmailJob::dispatch($email, $type, $code);

        // Return a generic 200 OK JSON response immediately to prevent email enumeration
        return response()->json([
            'status' => 'success',
            'message' => 'If the email exists, an OTP has been sent.'
        ], 200);
    }

    /**
     * Verify OTP code.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6'
        ]);

        $email = $request->input('email');
        $code = $request->input('code');
        $type = EmailType::OTP_VERIFICATION->value;

        try {
            $isValid = $this->otpService->verify($email, $type, $code);

            if (!$isValid) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid OTP code.'
                ], 401);
            }

            $user = User::where('email', $email)->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found in the system.'
                ], 404);
            }

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'OTP verified successfully.',
                'user' => $user,
                'token' => $token
            ], 200);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 401);
        }
    }

    /**
     * Send OTP for password reset.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->input('email');
        $type = EmailType::PASSWORD_RESET->value;

        // Generate OTP BEFORE dispatching the job (Immutable Payload Strategy)
        $code = $this->otpService->generate($email, $type);

        // Dispatch background job for password reset with immutable OTP
        SendOtpEmailJob::dispatch($email, $type, $code);

        // Anti-enumeration: Return generic success regardless of whether email exists
        return response()->json([
            'status' => 'success',
            'message' => 'If the email is registered, a password reset OTP has been sent.'
        ], 200);
    }

    /**
     * Verify OTP and reset password.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string|size:6',
            'new_password' => 'required|string|min:6'
        ]);

        $email = $request->input('email');
        $code = $request->input('code');
        $newPassword = $request->input('new_password');
        $type = EmailType::PASSWORD_RESET->value;

        try {
            $isValid = $this->otpService->verify($email, $type, $code);

            if (!$isValid) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid OTP code.'
                ], 401);
            }

            $user = User::where('email', $email)->first();

            if (!$user) {
                // Technically OTP verification passes if the email is in otps table, 
                // but user doesn't exist. Prevent action.
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found in the system.'
                ], 404);
            }

            // Securely hash the new password
            $user->password = Hash::make($newPassword);
            $user->save();

            // Note: OtpService automatically deletes the OTP upon successful verify.

            return response()->json([
                'status' => 'success',
                'message' => 'Password reset successfully.'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 401);
        }
    }

    /**
     * 🛡️ [P1 SECURITY 2026-07-25] Rotate current Sanctum token.
     * MUST stay inside auth:sanctum group — expired token cannot refresh (re-login).
     * Deletes ONLY currentAccessToken (not all devices).
     *
     * POST /api/auth/refresh  throttle:12,5
     */
    public function refreshToken(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated. Token invalid or expired.',
            ], 401);
        }

        try {
            $currentToken = $user->currentAccessToken();
            if ($currentToken) {
                $currentToken->delete();
                Log::info('[Auth] Token refreshed', ['user_id' => $user->id]);
            }

            $newToken = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'status' => 'success',
                'message' => 'Token refreshed successfully.',
                'token' => $newToken,
                'expires_in_days' => 30,
            ], 200);
        } catch (\Exception $e) {
            Log::error('[Auth] Token refresh failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Token refresh failed. Please login again.',
            ], 500);
        }
    }
}
