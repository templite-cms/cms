<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BF-008: Изменить уникальное ограничение key в block_fields.
 *
 * Текущий индекс unique(['block_id', 'key']) не позволяет иметь одинаковые key
 * на разных уровнях вложенности (например, top-level "title" и вложенный "title"
 * внутри array). Меняем на unique(['block_id', 'parent_id', 'key']).
 *
 * Примечание: parent_id может быть NULL (для top-level полей). В MySQL и PostgreSQL
 * NULL считается отличным от другого NULL в уникальных индексах, поэтому
 * два top-level поля с одинаковым key в одном блоке НЕ будут допустимы (корректно).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('block_fields', function (Blueprint $table) {
            // Удалить старый уникальный индекс
            $table->dropUnique(['block_id', 'key']);

            // Создать новый уникальный индекс с учетом parent_id
            $table->unique(['block_id', 'parent_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('block_fields', function (Blueprint $table) {
            $table->dropUnique(['block_id', 'parent_id', 'key']);
            $table->unique(['block_id', 'key']);
        });
    }
};
