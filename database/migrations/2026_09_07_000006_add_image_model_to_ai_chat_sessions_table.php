<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Модель генерации картинок выбирается на диалог — как и текстовая. */
    public function up(): void
    {
        Schema::table('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->string('image_model', 120)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->dropColumn('image_model');
        });
    }
};
