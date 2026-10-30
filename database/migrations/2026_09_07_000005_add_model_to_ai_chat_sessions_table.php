<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Модель выбирается на диалог: дешёвая для простых вопросов,
     * сильная для авторинга блоков. Пусто — берётся из настроек CMS.
     */
    public function up(): void
    {
        Schema::table('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->string('model', 120)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};
