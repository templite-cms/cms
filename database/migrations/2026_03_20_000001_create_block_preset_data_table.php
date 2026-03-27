<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
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
    }

    public function down(): void
    {
        Schema::dropIfExists('block_preset_data');
    }
};
