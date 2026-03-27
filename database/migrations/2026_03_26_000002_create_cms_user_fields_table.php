<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_user_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_type_id')->constrained('cms_user_types')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('cms_user_fields')->cascadeOnDelete();
            $table->string('name');
            $table->string('key', 64);
            $table->string('type', 50);
            $table->text('default_value')->nullable();
            $table->json('data')->nullable();
            $table->string('hint', 500)->nullable();
            $table->string('tab')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->unique(['user_type_id', 'key', 'parent_id'], 'user_fields_type_key_parent_unique');
            $table->index(['user_type_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_user_fields');
    }
};
