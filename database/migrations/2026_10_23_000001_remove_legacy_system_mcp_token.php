<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Старый общий MCP-токен (McpTokenService, удалён) был обычным Sanctum-токеном
 * суперадмина без mcp:*-abilities. После перехода на личные MCP-токены он
 * остался бы REST-токеном с полным доступом и без владельца в интерфейсе.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_config')) {
            return;
        }

        $tokenId = DB::table('cms_config')->where('key', 'mcp.token_id')->value('value');

        if ($tokenId && Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')->where('id', (int) $tokenId)->delete();
        }

        DB::table('cms_config')
            ->whereIn('key', ['mcp.token_id', 'mcp.token_suffix', 'mcp.token_expires_at'])
            ->delete();
    }

    public function down(): void
    {
        // Отозванный токен не восстановить: секрет хранится только хэшем.
    }
};
