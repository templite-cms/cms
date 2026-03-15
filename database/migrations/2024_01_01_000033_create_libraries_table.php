<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('libraries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('version')->nullable();
            $table->text('description')->nullable();
            $table->string('js_file')->nullable();
            $table->string('css_file')->nullable();
            $table->string('js_cdn')->nullable();
            $table->string('css_cdn')->nullable();
            $table->string('load_strategy')->default('local'); // local, cdn
            $table->integer('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('active');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('libraries');
    }
};
