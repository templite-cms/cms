<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\Models\Page;
use Templite\Cms\Services\PageAssetCompiler;

class CompileAssetsCommand extends Command
{
    protected $signature = 'cms:compile-assets
        {--page= : Compile assets for a specific page ID}
        {--fresh : Delete all compiled assets and recompile}';

    protected $description = 'Compile block CSS/JS assets for pages';

    public function handle(PageAssetCompiler $compiler): int
    {
        if ($this->option('fresh')) {
            $this->info('Cleaning all compiled assets...');
            $compiler->cleanAll();
        }

        if ($pageId = $this->option('page')) {
            $page = Page::find($pageId);
            if (!$page) {
                $this->error("Page #{$pageId} not found.");
                return 1;
            }
            $compiler->compile($page);
            $this->info("Assets compiled for page #{$pageId}.");
            return 0;
        }

        $this->info('Compiling assets for all published pages...');
        $count = $compiler->compileAll();
        $this->info("Done. Compiled assets for {$count} pages.");

        return 0;
    }
}
