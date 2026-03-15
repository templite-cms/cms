<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_field_value_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('global_field_value_id')->constrained('global_field_values')->cascadeOnDelete();
            $table->string('lang', 5);
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['global_field_value_id', 'lang'], 'gfv_translations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_field_value_translations');
    }
};
