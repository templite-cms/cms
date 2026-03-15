<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_queues', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->integer('priority')->default(0);
            $table->integer('tries')->default(3);
            $table->integer('timeout')->default(60);
            $table->integer('sleep')->default(3);
            $table->boolean('process_via_schedule')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('cms_queues')->insert([
            [
                'name' => 'default',
                'priority' => 0,
                'tries' => 3,
                'timeout' => 60,
                'sleep' => 3,
                'process_via_schedule' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'images',
                'priority' => 1,
                'tries' => 3,
                'timeout' => 120,
                'sleep' => 3,
                'process_via_schedule' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'email',
                'priority' => 2,
                'tries' => 3,
                'timeout' => 30,
                'sleep' => 3,
                'process_via_schedule' => false,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_queues');
    }
};
