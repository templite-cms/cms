<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('block_fields')->cascadeOnDelete();
            $table->string('type'); // text, textfield, number, img, file, editor, html, select, checkbox, radio, link, date, datetime, array, category, product, product_option, color
            $table->string('key');
            $table->text('default_value')->nullable();
            $table->json('data')->nullable();
            $table->foreignId('block_tab_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('block_section_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->unique(['block_id', 'key']);
            $table->index(['block_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_fields');
    }
};
