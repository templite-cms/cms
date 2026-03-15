<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('global_field_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('global_field_values')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['global_field_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_field_values');
    }
};
