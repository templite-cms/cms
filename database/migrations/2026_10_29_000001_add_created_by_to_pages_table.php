<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('order')
                ->constrained('managers')->nullOnDelete();
        });

        // Автор уже созданных страниц — менеджер из записи журнала о создании.
        // Если журнал почищен или страница пришла из импорта, автор остаётся пустым.
        $authors = DB::table('manager_logs')
            ->where('entity_type', 'page')
            ->where('action', 'create')
            ->whereNotNull('entity_id')
            ->orderBy('id')
            ->get(['entity_id', 'manager_id']);

        foreach ($authors as $log) {
            DB::table('pages')
                ->where('id', $log->entity_id)
                ->whereNull('created_by')
                ->update(['created_by' => $log->manager_id]);
        }
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
