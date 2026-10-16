<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role')) {
                $table->string('role')->default('mitra');
            }
            if (!Schema::hasColumn('users', 'account_status')) {
                $table->string('account_status')->default('active');
            }
            if (!Schema::hasColumn('users', 'subscription_end_date')) {
                $table->timestamp('subscription_end_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'account_status', 'subscription_end_date']);
        });
    }
};
