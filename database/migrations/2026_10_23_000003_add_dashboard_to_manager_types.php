<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_types', function (Blueprint $table) {
            // [{slug, width}], порядок — по массиву; null — раскладка по умолчанию
            $table->json('dashboard')->nullable()->after('permissions');
        });

        // Личные раскладки старого дашборда больше не читаются.
        // lazyById() — keyset-пагинация: each() по OFFSET пропускал бы строки,
        // когда settings становится null и строка выпадает из выборки.
        DB::table('managers')->whereNotNull('settings')->lazyById()->each(function ($row) {
            $settings = json_decode($row->settings, true);

            if (is_array($settings) && array_key_exists('dashboard', $settings)) {
                unset($settings['dashboard']);
                DB::table('managers')->where('id', $row->id)->update([
                    'settings' => $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('manager_types', function (Blueprint $table) {
            $table->dropColumn('dashboard');
        });
    }
};
