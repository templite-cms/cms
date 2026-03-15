<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('css_path')->nullable();
            $table->string('js_path')->nullable();
            $table->json('cdn_links')->nullable();
            $table->string('hash', 64);
            $table->timestamps();

            $table->unique('page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_assets');
    }
};
