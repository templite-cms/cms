<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_block_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_block_id')->constrained('page_blocks')->cascadeOnDelete();
            $table->string('lang', 5);
            $table->json('data')->nullable();
            $table->timestamps();
            $table->unique(['page_block_id', 'lang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_block_translations');
    }
};
