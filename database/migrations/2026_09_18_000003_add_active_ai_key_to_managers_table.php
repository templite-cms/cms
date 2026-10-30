<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Рабочий ключ менеджера: выбирается один раз в настройках, а не в каждом диалоге. */
    public function up(): void
    {
        Schema::table('managers', function (Blueprint $table) {
            $table->foreignId('active_ai_key_id')->nullable()->constrained('cms_ai_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('managers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_ai_key_id');
        });
    }
};
