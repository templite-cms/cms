<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пресеты:
 * - page_blocks.preset_id — только связь с глобальным пресетом (блок выводит
 *   его данные целиком); локальный пресет лишь заполняет данные при добавлении.
 * - Частичное переопределение полей связанного блока (field_overrides) убрано.
 * - Черновики превью пресетов (block_preset_data) убраны: превью по POST с данными.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('page_blocks')
            ->whereIn('preset_id', DB::table('block_presets')->where('type', 'local')->select('id'))
            ->update(['preset_id' => null]);

        Schema::table('page_blocks', function (Blueprint $table) {
            $table->dropColumn('field_overrides');
        });

        Schema::dropIfExists('block_preset_data');
    }

    public function down(): void
    {
        Schema::create('block_preset_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preset_id')->nullable()->constrained('block_presets')->nullOnDelete();
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('managers')->nullOnDelete();
            $table->string('change_type', 20)->default('native');
            $table->timestamps();

            $table->index(['preset_id', 'created_at']);
        });

        Schema::table('page_blocks', function (Blueprint $table) {
            $table->json('field_overrides')->nullable()->after('preset_id');
        });
    }
};
