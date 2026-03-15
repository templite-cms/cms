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
                'key' => 'api_rate_limit',
                'value' => '120',
                'type' => 'integer',
                'group' => 'system',
                'label' => 'Лимит API-запросов',
                'description' => 'Максимальное количество API-запросов в минуту для авторизованного пользователя.',
                'order' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cms_config')) {
            DB::table('cms_config')->where('key', 'api_rate_limit')->delete();
        }
    }
};
