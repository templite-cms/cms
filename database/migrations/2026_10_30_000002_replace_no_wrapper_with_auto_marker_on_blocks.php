<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Блок больше не оборачивается в <div class="cms-block">: корень блока — элемент
 * из его шаблона. Вместо «без обёртки» — флаг, вешать ли на корень data-block
 * автоматически (по метке рантайм находит экземпляры и передаёт их скрипту как el).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            $table->boolean('auto_marker')->default(true)->after('order');
        });

        DB::table('blocks')->where('no_wrapper', true)->update(['auto_marker' => false]);

        Schema::table('blocks', function (Blueprint $table) {
            $table->dropColumn('no_wrapper');
        });
    }

    public function down(): void
    {
        Schema::table('blocks', function (Blueprint $table) {
            $table->boolean('no_wrapper')->default(false)->after('order');
        });

        DB::table('blocks')->where('auto_marker', false)->update(['no_wrapper' => true]);

        Schema::table('blocks', function (Blueprint $table) {
            $table->dropColumn('auto_marker');
        });
    }
};
