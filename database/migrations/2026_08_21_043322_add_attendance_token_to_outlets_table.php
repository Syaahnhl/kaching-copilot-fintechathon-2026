<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->string('attendance_token', 64)->nullable()->unique()->after('timezone');
            $table->boolean('attendance_link_active')->default(false)->after('attendance_token');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['attendance_token', 'attendance_link_active']);
        });
    }
};
