<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->double('latitude')->nullable()->after('phone');
            $table->double('longitude')->nullable()->after('latitude');
            $table->integer('attendance_radius')->default(100)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('outlets', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'attendance_radius']);
        });
    }
};
