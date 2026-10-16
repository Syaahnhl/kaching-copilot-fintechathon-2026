<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * email_status for DIY email hygiene:
 * - legacy   = existing accounts before this feature (login allowed without OTP)
 * - pending  = new register, must verify OTP before login
 * - verified = OTP OK
 * - bounced  = Resend hard-bounce / complained — block login + re-register path
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'email_status')) {
                $table->string('email_status', 20)->nullable()->after('email_verified_at');
                $table->index('email_status');
            }
        });

        // Grandfather: existing rows stay usable without mass OTP.
        // verified if already has email_verified_at; else legacy.
        DB::table('users')
            ->whereNull('email_status')
            ->whereNotNull('email_verified_at')
            ->update(['email_status' => 'verified']);

        DB::table('users')
            ->whereNull('email_status')
            ->update(['email_status' => 'legacy']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'email_status')) {
                $table->dropIndex(['email_status']);
                $table->dropColumn('email_status');
            }
        });
    }
};
