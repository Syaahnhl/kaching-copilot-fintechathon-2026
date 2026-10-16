<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_telemetry_raw', function (Blueprint $table) {
            $table->id();
            $table->char('request_id', 36)->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('outlet_id')->nullable();
            $table->string('endpoint');
            $table->string('method', 10);
            $table->smallInteger('status_code');
            $table->unsignedInteger('latency_ms');
            $table->string('client_ip', 45);
            $table->text('user_agent')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_headers')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['endpoint', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_telemetry_raw');
    }
};
