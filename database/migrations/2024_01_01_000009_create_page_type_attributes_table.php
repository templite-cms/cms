<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_type_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_type_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key');
            $table->string('type'); // string, number, select, multi_select, boolean, date
            $table->json('options')->nullable();
            $table->boolean('filterable')->default(false);
            $table->boolean('sortable')->default(false);
            $table->boolean('required')->default(false);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->unique(['page_type_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_type_attributes');
    }
};
