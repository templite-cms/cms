<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('page_block_data');
    }
};
