<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('command', 255);
            $table->string('arguments', 255)->nullable();
            $table->string('expression', 50);
            $table->string('description', 255)->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('without_overlapping')->default(true);
            $table->timestamps();
        });

        // Update old command names in schedule_history (if any exist from prior closures)
        if (Schema::hasTable('cms_schedule_history')) {
            DB::table('cms_schedule_history')
                ->where('command', 'cms:cleanup-sessions')
                ->update(['command' => 'cms:cleanup-expired-sessions']);
        }

        $now = now();
        DB::table('cms_scheduled_tasks')->insert([
            [
                'command' => 'cms:process-scheduled-pages',
                'arguments' => null,
                'expression' => '* * * * *',
                'description' => 'Публикация/снятие страниц по расписанию',
                'is_system' => true,
                'is_active' => true,
                'without_overlapping' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'command' => 'cms:cleanup-exports',
                'arguments' => null,
                'expression' => '0 0 * * *',
                'description' => 'Очистка старых экспортов и импортов',
                'is_system' => true,
                'is_active' => true,
                'without_overlapping' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'command' => 'cms:cleanup-expired-sessions',
                'arguments' => null,
                'expression' => '0 * * * *',
                'description' => 'Очистка истёкших сессий менеджеров',
                'is_system' => true,
                'is_active' => true,
                'without_overlapping' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'command' => 'cms:cleanup-expired-2fa-devices',
                'arguments' => null,
                'expression' => '0 0 * * *',
                'description' => 'Очистка истёкших доверенных устройств 2FA',
                'is_system' => true,
                'is_active' => true,
                'without_overlapping' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'command' => 'cms:cleanup-schedule-history',
                'arguments' => null,
                'expression' => '0 0 * * *',
                'description' => 'Очистка истории расписания старше 7 дней',
                'is_system' => true,
                'is_active' => true,
                'without_overlapping' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_scheduled_tasks');
    }
};
