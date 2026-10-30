<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Диалог помнит, каким ключом шёл: модель хранится на диалоге, а модели
     * разных провайдеров несовместимы. При смене ключа модель сбрасывается.
     */
    public function up(): void
    {
        Schema::table('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->foreignId('key_id')->nullable()->after('manager_id')->constrained('cms_ai_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('key_id');
        });
    }
};
