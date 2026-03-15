<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_genitive')->nullable();
            $table->string('name_prepositional')->nullable();
            $table->string('name_accusative')->nullable();
            $table->string('slug', 100)->unique();
            $table->string('region')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->json('coordinates')->nullable();
            $table->json('extra_data')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        // Добавляем настройку multicity_enabled в cms_config
        if (Schema::hasTable('cms_config')) {
            DB::table('cms_config')->insertOrIgnore([
                'key' => 'multicity_enabled',
                'value' => '0',
                'type' => 'boolean',
                'group' => 'multicity',
                'label' => 'Мультигород',
                'description' => 'Включить поддержку нескольких городов',
                'order' => 100,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
