<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('page_type_attributes')->cascadeOnDelete();
            $table->string('value');
            $table->timestamps();

            $table->index(['attribute_id', 'value']);
            $table->index('page_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_attribute_values');
    }
};
