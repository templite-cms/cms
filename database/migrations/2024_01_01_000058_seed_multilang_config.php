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
                'key' => 'multilang_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'multilang',
                'label' => 'Мультиязычность',
                'description' => 'Включить поддержку нескольких языков',
                'order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cms_config')) {
            DB::table('cms_config')->where('key', 'multilang_enabled')->delete();
        }
    }
};
