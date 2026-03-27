<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_type_id')->constrained('cms_user_types')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('avatar_id')->nullable()->constrained('files')->nullOnDelete();
            $table->json('data')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();

            $table->unique(['email', 'user_type_id']);
            $table->index('user_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_users');
    }
};
