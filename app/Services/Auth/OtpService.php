<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class OtpService
{
    /**
     * Generate a new OTP.
     *
     * @param string $identifier
     * @param string $type
     * @return string
     */
    public function generate(string $identifier, string $type, int $length = 6): string
    {
        // Delete old OTPs for this identifier and type to prevent replay attacks
        DB::table('otps')->where('identifier', $identifier)->where('type', $type)->delete();

        // Generate crypto-secure code based on length
        $min = (int) str_pad('1', $length, '0');
        $max = (int) str_pad('9', $length, '9');
        $code = (string) random_int($min, $max);

        // Hash the code
        $hashedCode = Hash::make($code);

        // Save to DB with 5-minute expiry
        DB::table('otps')->insert([
            'identifier' => $identifier,
            'token' => $hashedCode,
            'type' => $type,
            'attempts' => 0,
            'expires_at' => Carbon::now()->addMinutes(5),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        return $code;
    }

    /**
     * Verify the provided OTP.
     *
     * @param string $identifier
     * @param string $type
     * @param string $code
     * @return bool
     * @throws ValidationException
     */
    public function verify(string $identifier, string $type, string $code): bool
    {
        $otp = DB::table('otps')
            ->where('identifier', $identifier)
            ->where('type', $type)
            ->first();

        if (!$otp) {
            return false;
        }

        // Check expiry
        if (Carbon::now()->greaterThan(Carbon::parse($otp->expires_at))) {
            DB::table('otps')->where('id', $otp->id)->delete();
            throw ValidationException::withMessages([
                'otp' => 'OTP has expired.'
            ]);
        }

        // Check max attempts (limit to 3)
        if ($otp->attempts >= 3) {
            DB::table('otps')->where('id', $otp->id)->delete();
            throw ValidationException::withMessages([
                'otp' => 'Maximum OTP attempts exceeded. Please request a new one.'
            ]);
        }

        // Verify hash
        if (!Hash::check($code, $otp->token)) {
            // Increment attempts
            DB::table('otps')
                ->where('id', $otp->id)
                ->increment('attempts');

            return false;
        }

        // Success: Delete OTP and return true
        DB::table('otps')->where('id', $otp->id)->delete();

        return true;
    }
}
