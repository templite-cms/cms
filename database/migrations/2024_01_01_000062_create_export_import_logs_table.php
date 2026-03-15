<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_import_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['export', 'import']);
            $table->foreignId('manager_id')->nullable()->constrained('managers')->nullOnDelete();
            $table->string('filename');
            $table->json('entity_summary');
            $table->json('conflicts')->nullable();
            $table->enum('status', ['completed', 'failed', 'partial'])->default('completed');
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamps();

            $table->index(['type', 'created_at']);
            $table->index('manager_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_import_logs');
    }
};
