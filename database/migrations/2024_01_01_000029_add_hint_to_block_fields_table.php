<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BF-001: Добавить поле hint для block_fields.
 *
 * Подсказка для контент-менеджера, отображается под полем при заполнении данных.
 * Альтернативно можно использовать data.hint, но отдельное поле удобнее
 * для фильтрации и единообразия.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('block_fields', function (Blueprint $table) {
            $table->string('hint', 500)->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table('block_fields', function (Blueprint $table) {
            $table->dropColumn('hint');
        });
    }
};
