<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('block_code_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('block_id')->constrained('blocks')->cascadeOnDelete();
            $table->longText('template')->nullable();
            $table->longText('style')->nullable();
            $table->longText('script')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('managers')->nullOnDelete();
            $table->string('source', 16)->default('manual'); // initial|manual|ai|restore
            $table->timestamp('created_at')->nullable();

            $table->index(['block_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('block_code_versions');
    }
};
