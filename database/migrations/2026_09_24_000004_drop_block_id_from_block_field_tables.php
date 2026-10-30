<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Владелец полей, вкладок и секций хранится только полиморфно (fieldable_*).
 *
 * block_id дублировал fieldable_id у блоков, код читал то одно, то другое,
 * и они расходились. Внешнего ключа по нему нет с миграции 000033, поэтому
 * при удалении блока его определения оставались сиротами — чистим их здесь.
 */
return new class extends Migration {
    /** Сначала поля: они ссылаются на секции и вкладки. */
    private const TABLES = ['block_fields', 'block_sections', 'block_tabs'];

    private const OWNERS = ['block' => 'blocks', 'template_page' => 'template_pages'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            foreach (self::OWNERS as $type => $ownerTable) {
                DB::table($table)
                    ->where('fieldable_type', $type)
                    ->whereNotExists(fn (Builder $q) => $q->select(DB::raw(1))
                        ->from($ownerTable)
                        ->whereColumn("{$ownerTable}.id", "{$table}.fieldable_id"))
                    ->delete();
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex("{$table}_block_id_order_index");
                $t->dropColumn('block_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->unsignedBigInteger('block_id')->nullable()->after('fieldable_id');
                $t->index(['block_id', 'order']);
            });

            DB::table($table)
                ->where('fieldable_type', 'block')
                ->update(['block_id' => DB::raw('fieldable_id')]);
        }
    }
};
