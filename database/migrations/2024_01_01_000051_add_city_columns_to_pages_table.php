<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('city_scope', 20)->default('global')->after('status');
            $table->foreignId('city_id')->nullable()->after('city_scope')
                ->constrained('cities')->nullOnDelete();

            $table->index(['city_scope']);
            $table->index(['city_id']);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropForeign(['city_id']);
            $table->dropIndex(['city_scope']);
            $table->dropIndex(['city_id']);
            $table->dropColumn(['city_scope', 'city_id']);
        });
    }
};
