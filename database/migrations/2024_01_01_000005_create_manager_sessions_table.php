<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->string('user_agent')->nullable();
            $table->string('ip')->nullable();
            $table->timestamp('last_active')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['manager_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_sessions');
    }
};
