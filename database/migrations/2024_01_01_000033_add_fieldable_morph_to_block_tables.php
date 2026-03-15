<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Добавление полиморфных колонок fieldable_type / fieldable_id
 * в таблицы block_tabs, block_sections и block_fields.
 *
 * Это позволяет переиспользовать систему полей не только для блоков,
 * но и для шаблонных страниц (TemplatePage) и других сущностей.
 *
 * Существующие записи получают fieldable_type='block' и fieldable_id=block_id.
 * Колонка block_id становится nullable для обратной совместимости.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- block_tabs ---
        Schema::table('block_tabs', function (Blueprint $table) {
            $table->string('fieldable_type', 50)->nullable()->after('id');
            $table->unsignedBigInteger('fieldable_id')->nullable()->after('fieldable_type');
        });

        DB::table('block_tabs')
            ->whereNull('fieldable_type')
            ->update([
                'fieldable_type' => 'block',
                'fieldable_id' => DB::raw('block_id'),
            ]);

        Schema::table('block_tabs', function (Blueprint $table) {
            // Убираем foreign key constraint с block_id
            $table->dropForeign(['block_id']);

            // Делаем block_id nullable
            $table->unsignedBigInteger('block_id')->nullable()->change();

            // Индекс для полиморфной связи
            $table->index(['fieldable_type', 'fieldable_id', 'order'], 'block_tabs_fieldable_order_index');
        });

        // --- block_sections ---
        Schema::table('block_sections', function (Blueprint $table) {
            $table->string('fieldable_type', 50)->nullable()->after('id');
            $table->unsignedBigInteger('fieldable_id')->nullable()->after('fieldable_type');
        });

        DB::table('block_sections')
            ->whereNull('fieldable_type')
            ->update([
                'fieldable_type' => 'block',
                'fieldable_id' => DB::raw('block_id'),
            ]);

        Schema::table('block_sections', function (Blueprint $table) {
            $table->dropForeign(['block_id']);
            $table->unsignedBigInteger('block_id')->nullable()->change();
            $table->index(['fieldable_type', 'fieldable_id', 'order'], 'block_sections_fieldable_order_index');
        });

        // --- block_fields ---
        Schema::table('block_fields', function (Blueprint $table) {
            $table->string('fieldable_type', 50)->nullable()->after('id');
            $table->unsignedBigInteger('fieldable_id')->nullable()->after('fieldable_type');
        });

        DB::table('block_fields')
            ->whereNull('fieldable_type')
            ->update([
                'fieldable_type' => 'block',
                'fieldable_id' => DB::raw('block_id'),
            ]);

        Schema::table('block_fields', function (Blueprint $table) {
            // Удалить текущий уникальный индекс (block_id, parent_id, key) из миграции 000030
            $table->dropUnique('block_fields_block_id_parent_id_key_unique');

            $table->dropForeign(['block_id']);
            $table->unsignedBigInteger('block_id')->nullable()->change();

            // Новый уникальный индекс с полиморфными колонками
            $table->unique(
                ['fieldable_type', 'fieldable_id', 'key', 'parent_id'],
                'block_fields_fieldable_key_parent_unique'
            );

            // Индекс для полиморфной связи
            $table->index(['fieldable_type', 'fieldable_id', 'order'], 'block_fields_fieldable_order_index');
        });
    }

    public function down(): void
    {
        // --- block_fields (откат) ---
        Schema::table('block_fields', function (Blueprint $table) {
            $table->dropIndex('block_fields_fieldable_order_index');
            $table->dropUnique('block_fields_fieldable_key_parent_unique');

            // Восстанавливаем block_id как NOT NULL с foreign key
            $table->unsignedBigInteger('block_id')->nullable(false)->change();
            $table->foreign('block_id')->references('id')->on('blocks')->cascadeOnDelete();

            // Восстанавливаем уникальный индекс из миграции 000030
            $table->unique(['block_id', 'parent_id', 'key']);

            $table->dropColumn(['fieldable_type', 'fieldable_id']);
        });

        // --- block_sections (откат) ---
        Schema::table('block_sections', function (Blueprint $table) {
            $table->dropIndex('block_sections_fieldable_order_index');

            $table->unsignedBigInteger('block_id')->nullable(false)->change();
            $table->foreign('block_id')->references('id')->on('blocks')->cascadeOnDelete();

            $table->dropColumn(['fieldable_type', 'fieldable_id']);
        });

        // --- block_tabs (откат) ---
        Schema::table('block_tabs', function (Blueprint $table) {
            $table->dropIndex('block_tabs_fieldable_order_index');

            $table->unsignedBigInteger('block_id')->nullable(false)->change();
            $table->foreign('block_id')->references('id')->on('blocks')->cascadeOnDelete();

            $table->dropColumn(['fieldable_type', 'fieldable_id']);
        });
    }
};
