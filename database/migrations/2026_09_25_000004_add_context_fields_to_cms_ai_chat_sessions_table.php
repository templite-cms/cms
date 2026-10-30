<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Резюме сжатой части диалога и последний реальный размер промпта —
     * по нему уточняется оценка токенов.
     */
    public function up(): void
    {
        Schema::table('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->longText('summary')->nullable();
            $table->unsignedBigInteger('summarized_until_message_id')->nullable();
            $table->unsignedInteger('last_prompt_tokens')->nullable();
            $table->unsignedInteger('last_prompt_chars')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['summary', 'summarized_until_message_id', 'last_prompt_tokens', 'last_prompt_chars']);
        });
    }
};
