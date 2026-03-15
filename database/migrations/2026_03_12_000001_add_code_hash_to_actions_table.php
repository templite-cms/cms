<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет столбец code_hash в таблицу actions для проверки целостности
 * PHP-файлов Actions при загрузке (защита от подмены файлов).
 *
 * Часть TASK-S05: замена blacklist-валидации на токенизатор.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->string('code_hash', 64)->nullable()->after('description')
                ->comment('SHA-256 хэш кода Action для проверки целостности');
        });
    }

    public function down(): void
    {
        Schema::table('actions', function (Blueprint $table) {
            $table->dropColumn('code_hash');
        });
    }
};
