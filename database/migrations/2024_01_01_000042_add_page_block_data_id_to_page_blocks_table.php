<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_blocks', function (Blueprint $table) {
            $table->foreignId('page_block_data_id')
                ->nullable()
                ->after('cache_key')
                ->constrained('page_block_data')
                ->nullOnDelete();
        });

        // Migrate existing data: create PageBlockData for each page_block with non-null data
        $pageBlocks = DB::table('page_blocks')
            ->whereNotNull('data')
            ->where('data', '!=', 'null')
            ->get();

        foreach ($pageBlocks as $pb) {
            $versionId = DB::table('page_block_data')->insertGetId([
                'page_block_id' => $pb->id,
                'block_id' => $pb->block_id,
                'data' => $pb->data,
                'action_params' => $pb->action_params,
                'user_id' => null,
                'change_type' => 'migration',
                'created_at' => $pb->updated_at ?? $pb->created_at ?? now(),
                'updated_at' => $pb->updated_at ?? $pb->created_at ?? now(),
            ]);

            DB::table('page_blocks')
                ->where('id', $pb->id)
                ->update(['page_block_data_id' => $versionId]);
        }
    }

    public function down(): void
    {
        Schema::table('page_blocks', function (Blueprint $table) {
            $table->dropForeign(['page_block_data_id']);
            $table->dropColumn('page_block_data_id');
        });
    }
};
