<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_library', function (Blueprint $table) {
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_id')->constrained()->cascadeOnDelete();

            $table->primary(['block_id', 'library_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_library');
    }
};
