<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ход агента — ответ на одно сообщение пользователя. Живёт отдельно от
     * HTTP-запроса: браузер может уйти, ход продолжится.
     */
    public function up(): void
    {
        Schema::create('cms_ai_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cms_ai_chat_sessions')->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('managers')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('cms_ai_chat_messages')->cascadeOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('cms_ai_chat_messages')->nullOnDelete();
            $table->string('status', 16)->default('queued');
            $table->string('driver', 16)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('error')->nullable();
            $table->json('usage')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
            $table->index(['manager_id', 'status']);
            $table->index('message_id');
            $table->index('assistant_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_runs');
    }
};
