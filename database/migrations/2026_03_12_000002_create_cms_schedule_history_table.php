<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_schedule_history', function (Blueprint $table) {
            $table->id();
            $table->string('command');
            $table->string('status', 20); // success, fail
            $table->text('output')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('ran_at');

            $table->index('command', 'idx_schedule_history_command');
            $table->index('ran_at', 'idx_schedule_history_ran_at');
            $table->index('status', 'idx_schedule_history_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_schedule_history');
    }
};
