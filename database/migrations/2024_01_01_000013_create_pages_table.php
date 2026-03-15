<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('url', 500)->unique();
            $table->string('alias');
            $table->foreignId('parent_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->foreignId('type_id')->nullable()->constrained('page_types')->nullOnDelete();
            $table->string('title');
            $table->string('bread_title')->nullable();
            $table->json('seo_data')->nullable();
            $table->json('social_data')->nullable();
            $table->foreignId('template_page_id')->nullable()->constrained('template_pages')->nullOnDelete();
            $table->tinyInteger('status')->default(0); // 0=draft, 1=published
            $table->boolean('display_tree')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->foreignId('img')->nullable()->constrained('files')->nullOnDelete();
            $table->integer('order')->default(100);
            $table->timestamps();

            $table->index('parent_id');
            $table->index('type_id');
            $table->index('status');
            $table->index(['parent_id', 'order']);
            $table->index(['type_id', 'status', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
