<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\Models\Page;

class ProcessScheduledPagesCommand extends Command
{
    protected $signature = 'cms:process-scheduled-pages';

    protected $description = 'Publish/unpublish pages based on publish_at/unpublish_at dates';

    public function handle(): int
    {
        $now = now();

        // Auto-publish: status=0, publish_at <= now
        $published = Page::where('status', 0)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', $now)
            ->update(['status' => 1]);

        // Auto-unpublish: status=1, unpublish_at <= now
        $unpublished = Page::where('status', 1)
            ->whereNotNull('unpublish_at')
            ->where('unpublish_at', '<=', $now)
            ->update(['status' => 0, 'unpublish_at' => null]);

        if ($published || $unpublished) {
            $this->info("Published: {$published}, Unpublished: {$unpublished}");
        }

        return self::SUCCESS;
    }
}
