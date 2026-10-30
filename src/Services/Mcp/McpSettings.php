<?php

namespace Templite\Cms\Services\Mcp;

use Templite\Cms\Models\CmsConfig;

/** Общий рубильник внешнего MCP-доступа (настройки ядра → «Система»). */
final class McpSettings
{
    public static function enabled(): bool
    {
        return (bool) CmsConfig::getValue('mcp.enabled', false);
    }
}
