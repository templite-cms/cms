<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Стартовый контент блока задаётся пресетом по умолчанию, а не значениями полей.
 * Поля шаблонов живут в той же таблице и теряют дефолт вместе с блоками:
 * значение «на все страницы» — это глобальные настройки.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('block_fields', function (Blueprint $table) {
            $table->dropColumn('default_value');
        });
    }

    public function down(): void
    {
        Schema::table('block_fields', function (Blueprint $table) {
            $table->text('default_value')->nullable()->after('key');
        });
    }
};
