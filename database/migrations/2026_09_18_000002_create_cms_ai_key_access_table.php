<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Кому владелец разрешил пользоваться своим ключом. */
    public function up(): void
    {
        Schema::create('cms_ai_key_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('key_id')->constrained('cms_ai_keys')->cascadeOnDelete();
            $table->foreignId('manager_id')->constrained('managers')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['key_id', 'manager_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_key_access');
    }
};
