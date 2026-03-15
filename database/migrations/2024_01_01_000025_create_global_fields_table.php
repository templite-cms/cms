<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_fields', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable()->constrained('global_fields')->cascadeOnDelete();
            $table->string('type');
            $table->string('key');
            $table->text('default_value')->nullable();
            $table->json('data')->nullable();
            $table->foreignId('global_field_page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('global_field_section_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->unique(['parent_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_fields');
    }
};
