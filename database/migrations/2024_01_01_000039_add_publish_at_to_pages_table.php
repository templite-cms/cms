<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('publish_at')->nullable()->after('status');
            $table->timestamp('unpublish_at')->nullable()->after('publish_at');
            $table->index(['status', 'publish_at', 'unpublish_at']);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropIndex(['status', 'publish_at', 'unpublish_at']);
            $table->dropColumn(['publish_at', 'unpublish_at']);
        });
    }
};
