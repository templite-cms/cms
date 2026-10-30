<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Мультигород удалён из ядра: города, городские версии страниц, плейсхолдеры {city}.
 *
 * Исходные миграции 2024_01_01_000050–000053 удалены — на свежей установке таблиц
 * и колонок нет, и эта миграция ничего не делает. Для существующих баз она снимает
 * колонки pages.city_id/city_scope с их FK и индексами, удаляет таблицы городов
 * и настройку multicity_enabled. Данные не сохраняются: живых установок с городами нет.
 *
 * Каждый шаг — отдельный Schema::table и под проверкой наличия: на SQLite снятие FK
 * и колонок пересобирает таблицу, а повторный запуск на частично очищенной базе
 * не должен падать. Восстановление — из тега pre-cut-i18n-city-users.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('pages', 'city_id')) {
            $hasForeign = collect(Schema::getForeignKeys('pages'))
                ->contains(fn (array $fk) => $fk['columns'] === ['city_id']);

            if ($hasForeign) {
                Schema::table('pages', function (Blueprint $table) {
                    $table->dropForeign(['city_id']);
                });
            }

            if (Schema::hasIndex('pages', 'pages_city_id_index')) {
                Schema::table('pages', function (Blueprint $table) {
                    $table->dropIndex('pages_city_id_index');
                });
            }

            Schema::table('pages', function (Blueprint $table) {
                $table->dropColumn('city_id');
            });
        }

        if (Schema::hasColumn('pages', 'city_scope')) {
            if (Schema::hasIndex('pages', 'pages_city_scope_index')) {
                Schema::table('pages', function (Blueprint $table) {
                    $table->dropIndex('pages_city_scope_index');
                });
            }

            Schema::table('pages', function (Blueprint $table) {
                $table->dropColumn('city_scope');
            });
        }

        // Сначала зависимые: city_page_blocks → city_pages → cities
        Schema::dropIfExists('city_page_blocks');
        Schema::dropIfExists('city_pages');
        Schema::dropIfExists('cities');

        if (Schema::hasTable('cms_config')) {
            DB::table('cms_config')->where('key', 'multicity_enabled')->delete();
        }
    }

    public function down(): void
    {
        //
    }
};
