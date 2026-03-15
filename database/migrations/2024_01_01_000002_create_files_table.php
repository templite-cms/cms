<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('path');
            $table->string('disk')->default('public');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime')->nullable();
            $table->string('type')->default('other'); // image, video, document, archive, other
            $table->foreignId('parent_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->json('sizes')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('folder_id')->nullable()->constrained('file_folders')->nullOnDelete();
            $table->timestamps();

            $table->index('type');
            $table->index('folder_id');
            $table->index(['folder_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
