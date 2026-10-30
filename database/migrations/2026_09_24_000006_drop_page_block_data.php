<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Версии данных блоков, черновики превью и серверный буфер копирования убраны.
 *
 * Данные экземпляра — только page_blocks.data; превью получает несохранённые
 * данные в теле запроса, буфер живёт в localStorage редактора.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('page_blocks', function (Blueprint $table) {
            $table->dropForeign(['page_block_data_id']);
            $table->dropColumn('page_block_data_id');
        });

        Schema::dropIfExists('page_block_data');
    }

    public function down(): void
    {
        Schema::create('page_block_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_block_id')->nullable()->constrained('page_blocks')->nullOnDelete();
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->json('data')->nullable();
            $table->json('action_params')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('managers')->nullOnDelete();
            $table->string('change_type', 20)->default('native');
            $table->timestamps();

            $table->index(['page_block_id', 'created_at']);
        });

        Schema::table('page_blocks', function (Blueprint $table) {
            $table->foreignId('page_block_data_id')->nullable()->after('cache_key')
                ->constrained('page_block_data')->nullOnDelete();
        });
    }
};
