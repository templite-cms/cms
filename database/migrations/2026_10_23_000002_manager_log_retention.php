<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // (manager_id, created_at) и (entity_type, entity_id) уже есть; ленте
        // и очистке нужен индекс по одному времени
        Schema::table('manager_logs', function (Blueprint $table) {
            $table->index('created_at');
        });

        DB::table('cms_config')->insertOrIgnore([
            'key' => 'manager_log_retention_days',
            'value' => '90',
            'type' => 'integer',
            'group' => 'system',
            'label' => 'Хранить журнал действий (дней)',
            'description' => 'Записи старше удаляются раз в сутки. 0 — хранить без ограничений.',
            'order' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! DB::table('cms_scheduled_tasks')->where('command', 'cms:prune-manager-log')->exists()) {
            DB::table('cms_scheduled_tasks')->insert([
                'command' => 'cms:prune-manager-log',
                'arguments' => null,
                'expression' => '30 3 * * *',
                'description' => 'Очистка журнала действий старше срока хранения',
                'is_system' => true,
                'is_active' => true,
                'without_overlapping' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('cms_scheduled_tasks')->where('command', 'cms:prune-manager-log')->delete();
        DB::table('cms_config')->where('key', 'manager_log_retention_days')->delete();

        Schema::table('manager_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
