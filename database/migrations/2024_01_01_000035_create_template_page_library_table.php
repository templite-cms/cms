<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_page_library', function (Blueprint $table) {
            $table->foreignId('template_page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();

            $table->primary(['template_page_id', 'library_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_page_library');
    }
};
