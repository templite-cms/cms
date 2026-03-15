<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->foreignId('source_page_id')->constrained('pages')->cascadeOnDelete();
            $table->boolean('is_materialized')->default(false);
            $table->foreignId('materialized_page_id')->nullable()
                ->constrained('pages')->nullOnDelete();

            $table->string('title_override')->nullable();
            $table->string('bread_title_override')->nullable();
            $table->json('seo_data_override')->nullable();
            $table->json('social_data_override')->nullable();
            $table->json('template_data_override')->nullable();
            $table->tinyInteger('status_override')->nullable();

            $table->timestamps();

            $table->unique(['city_id', 'source_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_pages');
    }
};
