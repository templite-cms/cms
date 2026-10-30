<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Удаление мультиязычности: языки, переводы страниц, данных блоков, значений
 * глобальных полей и атрибутов магазина, ключ multilang_enabled в cms_config.
 *
 * Исходные миграции фичи удалены, поэтому на свежей установке этих таблиц нет
 * и миграция ничего не делает. Для существующих баз данные переводов не
 * сохраняются: живых установок с переводами нет. Функционал при необходимости
 * восстанавливается из тега pre-cut-i18n-city-users отдельным пакетом.
 */
return new class extends Migration {
    public function up(): void
    {
        // Переводы ссылаются на pages, page_blocks и global_field_values, но не
        // друг на друга и не на languages — порядок удаления не важен.
        Schema::dropIfExists('page_block_translations');
        Schema::dropIfExists('page_translations');
        Schema::dropIfExists('global_field_value_translations');
        Schema::dropIfExists('languages');

        // Магазин может быть не установлен: dropIfExists переживает отсутствие таблиц.
        Schema::dropIfExists('shop_attribute_value_translations');
        Schema::dropIfExists('shop_attribute_translations');

        if (Schema::hasTable('cms_config')) {
            DB::table('cms_config')->where('key', 'multilang_enabled')->delete();
        }
    }

    public function down(): void
    {
        // Удаление необратимо: схема и данные восстанавливаются из тега pre-cut-i18n-city-users.
    }
};
