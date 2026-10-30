<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Правки, которые агент предложил в редактор. Редактор мог быть закрыт или
     * перезагружен — тогда правку применят при следующем его открытии.
     */
    public function up(): void
    {
        Schema::create('cms_ai_editor_ops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->nullable()->constrained('cms_ai_runs')->nullOnDelete();
            $table->foreignId('manager_id')->constrained('managers')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->string('target_id', 64);
            $table->json('op');
            $table->string('status', 16)->default('pending');
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['manager_id', 'target_type', 'target_id', 'status'], 'cms_ai_editor_ops_target_index');
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_ai_editor_ops');
    }
};
