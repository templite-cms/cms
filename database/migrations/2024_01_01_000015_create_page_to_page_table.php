<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_to_page', function (Blueprint $table) {
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_page_id')->constrained('pages')->cascadeOnDelete();
            $table->primary(['page_id', 'related_page_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_to_page');
    }
};
