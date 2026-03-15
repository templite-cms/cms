<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managers', function (Blueprint $table) {
            $table->id();
            $table->string('login')->unique();
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('password');
            $table->foreignId('type_id')->constrained('manager_types');
            $table->json('settings')->nullable();
            $table->json('personal_permissions')->nullable();
            $table->boolean('use_personal_permissions')->default(false);
            $table->foreignId('avatar_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managers');
    }
};
