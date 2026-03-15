<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_page_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_page_id')->constrained('city_pages')->cascadeOnDelete();
            $table->foreignId('page_block_id')->nullable()
                ->constrained('page_blocks')->cascadeOnDelete();
            $table->foreignId('block_id')->nullable()
                ->constrained('blocks')->cascadeOnDelete();
            $table->string('action', 10)->default('override'); // override, hide, add
            $table->json('data_override')->nullable();
            $table->integer('order_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_page_blocks');
    }
};
