<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_blocks', function (Blueprint $table) {
            $table->foreignId('preset_id')
                ->nullable()
                ->after('page_block_data_id')
                ->constrained('block_presets')
                ->nullOnDelete();

            $table->json('field_overrides')
                ->nullable()
                ->after('preset_id');
        });
    }

    public function down(): void
    {
        Schema::table('page_blocks', function (Blueprint $table) {
            $table->dropForeign(['preset_id']);
            $table->dropColumn(['preset_id', 'field_overrides']);
        });
    }
};
