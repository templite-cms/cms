<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ключ провайдера принадлежит менеджеру: он платит за расход и решает,
     * кому дать ключом пользоваться. Модели и лимиты живут здесь же —
     * у разных провайдеров разные имена моделей.
     */
    public function up(): void
    {
        Schema::create('cms_ai_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_manager_id')->constrained('managers')->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('provider', 32)->default('openrouter');
            $table->text('api_key');
            $table->string('model', 120)->nullable();
            $table->string('image_model', 120)->nullable();
            $table->unsignedSmallInteger('max_steps')->nullable();
            $table->unsignedTinyInteger('max_images')->nullable();
            $table->timestamps();

            $table->index('owner_manager_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_keys');
    }
};
