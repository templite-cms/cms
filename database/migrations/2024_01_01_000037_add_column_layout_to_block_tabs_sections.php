<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('block_tabs', function (Blueprint $table) {
            $table->unsignedTinyInteger('columns')->default(1)->after('order');
            $table->json('column_widths')->nullable()->after('columns');
        });

        Schema::table('block_sections', function (Blueprint $table) {
            $table->unsignedTinyInteger('column_index')->default(0)->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('block_tabs', function (Blueprint $table) {
            $table->dropColumn(['columns', 'column_widths']);
        });

        Schema::table('block_sections', function (Blueprint $table) {
            $table->dropColumn('column_index');
        });
    }
};
