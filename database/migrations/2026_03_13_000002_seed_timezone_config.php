<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cms_config')) {
            DB::table('cms_config')->insertOrIgnore([
                'key' => 'timezone',
                'value' => 'Europe/Moscow',
                'type' => 'select',
                'group' => 'system',
                'label' => 'Часовой пояс',
                'description' => 'Часовой пояс для отображения дат и времени в CMS.',
                'order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cms_config')) {
            DB::table('cms_config')->where('key', 'timezone')->delete();
        }
    }
};
