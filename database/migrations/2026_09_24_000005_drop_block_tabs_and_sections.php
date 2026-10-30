<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поля блоков и шаблонов — плоский упорядоченный список (плюс вложенность повторителей).
 * Вкладки, секции и сетка колонок убраны: у блока единицы полей, группировка
 * только усложняла редактор, экспорт и проверки владельца.
 * Разделы глобальных настроек (global_field_pages/sections) — отдельные таблицы, не затронуты.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('block_fields', function (Blueprint $table) {
            $table->dropForeign(['block_tab_id']);
            $table->dropForeign(['block_section_id']);
            $table->dropColumn(['block_tab_id', 'block_section_id']);
        });

        Schema::dropIfExists('block_sections');
        Schema::dropIfExists('block_tabs');
    }

    public function down(): void
    {
        Schema::create('block_tabs', function (Blueprint $table) {
            $table->id();
            $table->string('fieldable_type', 50)->nullable();
            $table->unsignedBigInteger('fieldable_id')->nullable();
            $table->string('name');
            $table->integer('order')->default(0);
            $table->unsignedTinyInteger('columns')->default(1);
            $table->json('column_widths')->nullable();
            $table->timestamps();

            $table->index(['fieldable_type', 'fieldable_id', 'order'], 'block_tabs_fieldable_order_index');
        });

        Schema::create('block_sections', function (Blueprint $table) {
            $table->id();
            $table->string('fieldable_type', 50)->nullable();
            $table->unsignedBigInteger('fieldable_id')->nullable();
            $table->string('name');
            $table->foreignId('block_tab_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('order')->default(0);
            $table->unsignedTinyInteger('column_index')->default(0);
            $table->timestamps();

            $table->index(['fieldable_type', 'fieldable_id', 'order'], 'block_sections_fieldable_order_index');
        });

        Schema::table('block_fields', function (Blueprint $table) {
            $table->foreignId('block_tab_id')->nullable()->after('hint')->constrained()->nullOnDelete();
            $table->foreignId('block_section_id')->nullable()->after('block_tab_id')->constrained()->nullOnDelete();
        });
    }
};
