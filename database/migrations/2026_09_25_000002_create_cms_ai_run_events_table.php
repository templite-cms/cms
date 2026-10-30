<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Журнал событий хода: автоинкрементный id служит курсором подписки. */
    public function up(): void
    {
        Schema::create('cms_ai_run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('cms_ai_runs')->cascadeOnDelete();
            $table->string('type', 32);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_run_events');
    }
};
