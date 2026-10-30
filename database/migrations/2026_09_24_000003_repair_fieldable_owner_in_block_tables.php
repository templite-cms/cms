<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Чинит владельца полей, табов и секций.
 *
 * - Копирование блока меняло у копий только block_id, и fieldable_id указывал
 *   на исходный блок: поля копии показывались в дереве оригинала.
 * - Импорт писал в fieldable_type имя класса вместо morph-алиаса, и такие
 *   записи не находились ни одной выборкой полей.
 */
return new class extends Migration {
    private const TABLES = ['block_fields', 'block_tabs', 'block_sections'];

    private const ALIASES = [
        'Templite\\Cms\\Models\\Block' => 'block',
        'Templite\\Cms\\Models\\TemplatePage' => 'template_page',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            foreach (self::ALIASES as $class => $alias) {
                DB::table($table)->where('fieldable_type', $class)->update(['fieldable_type' => $alias]);
            }

            DB::table($table)
                ->where('fieldable_type', 'block')
                ->whereNotNull('block_id')
                ->whereColumn('fieldable_id', '!=', 'block_id')
                ->update(['fieldable_id' => DB::raw('block_id')]);
        }
    }

    public function down(): void
    {
        // Исправление данных не откатывается.
    }
};
