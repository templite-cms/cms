<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_ai_chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')->constrained('managers')->cascadeOnDelete();
            $table->string('title', 255)->nullable();
            $table->timestamps();

            $table->index(['manager_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_chat_sessions');
    }
};
