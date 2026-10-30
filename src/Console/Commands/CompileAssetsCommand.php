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
        // compileAll() отдаёт ['compiled' => n, 'errors' => n] — в строку его
        // подставлять нельзя, команда падала на «Array to string conversion».
        ['compiled' => $compiled, 'errors' => $errors] = $compiler->compileAll();

        $this->info("Done. Compiled assets for {$compiled} pages.");

        if ($errors > 0) {
            $this->warn("Failed for {$errors} pages — details in the log.");
        }

        return $errors > 0 ? 1 : 0;
    }
}
