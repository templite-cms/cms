<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Сгенерированные ассистентом изображения ждут подтверждения пользователя.
     * До него файл считается «в карантине»: не показывается в библиотеке
     * и не может быть подставлен в поле блока.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->boolean('ai_pending')->default(false)->index()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['ai_pending']);
            $table->dropColumn('ai_pending');
        });
    }
};
