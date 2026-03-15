<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->string('lang', 5);
            $table->string('title')->nullable();
            $table->string('bread_title')->nullable();
            $table->json('seo_data')->nullable();
            $table->json('social_data')->nullable();
            $table->timestamps();
            $table->unique(['page_id', 'lang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_translations');
    }
};
