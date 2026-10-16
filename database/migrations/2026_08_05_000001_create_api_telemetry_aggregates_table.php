<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_telemetry_aggregates', function (Blueprint $table) {
            $table->id();
            $table->timestamp('minute_bucket')->unique();
            $table->unsignedInteger('total_requests')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('p50_ms')->default(0);
            $table->unsignedInteger('p90_ms')->default(0);
            $table->unsignedInteger('p95_ms')->default(0);
            $table->unsignedInteger('p99_ms')->default(0);
            $table->timestamps();

            $table->index('minute_bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_telemetry_aggregates');
    }
};
