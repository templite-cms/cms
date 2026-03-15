<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')->constrained('managers')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('user_agent')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['manager_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_trusted_devices');
    }
};
