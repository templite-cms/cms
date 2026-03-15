<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('action_id')->constrained()->cascadeOnDelete();
            $table->json('params')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['block_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_actions');
    }
};
