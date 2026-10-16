<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->string('id')->primary(); // UUID string
            $table->unsignedBigInteger('user_id'); // tenant admin user_id
            $table->unsignedBigInteger('outlet_id');
            $table->string('type'); // general / order
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_uuid')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'outlet_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->string('id')->primary(); // UUID string
            $table->string('conversation_id');
            $table->string('client_message_id')->nullable()->index();
            $table->text('body');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_uuid')->nullable();
            $table->unsignedBigInteger('sender_id'); // staff_id
            $table->string('sender_role'); // staff / kitchen
            $table->string('priority')->default('normal');
            $table->string('status')->default('sent');
            $table->unsignedBigInteger('user_id'); // tenant admin
            $table->unsignedBigInteger('outlet_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->onDelete('cascade');
            $table->index(['user_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
