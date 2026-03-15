<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Facades\File;
use Templite\Cms\Models\Library;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageAsset;
use Templite\Cms\Models\PageBlock;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\TemplatePage;

class PageAssetCompiler
{
    public function __construct(
        protected BlockRenderer $blockRenderer,
    ) {}

    /**
     * Compile all assets for a page into bundle files.
     */
    public function compile(Page $page): PageAsset
    {
        $libraries = $this->collectLibraries($page);
        $blockSlugs = $this->collectBlockSlugs($page);
        $componentSlugs = $this->extractComponentSlugs($blockSlugs, $page);
        $css = $this->buildCss($libraries, $blockSlugs, $page, $componentSlugs);
        $js = $this->buildJs($libraries, $blockSlugs, $page, $componentSlugs);
        $cdnLinks = $this->collectCdnLinks($libraries);
        $hash = substr(md5($css . $js . json_encode($cdnLinks)), 0, 8);

        $basePath = storage_path('app/public/cms/assets/pages/' . $page->id);
        $this->ensureDirectory($basePath);
        $this->cleanDirectory($basePath);

        $cssPath = null;
        $jsPath = null;

        if (trim($css) !== '') {
            $cssFile = "blocks.{$hash}.css";
            File::put($basePath . '/' . $cssFile, $css);
            $cssPath = "cms/assets/pages/{$page->id}/{$cssFile}";
        }

        if (trim($js) !== '') {
            $jsFile = "blocks.{$hash}.js";
            File::put($basePath . '/' . $jsFile, $js);
            $jsPath = "cms/assets/pages/{$page->id}/{$jsFile}";
        }

        return PageAsset::updateOrCreate(
            ['page_id' => $page->id],
            [
                'css_path' => $cssPath,
                'js_path' => $jsPath,
                'cdn_links' => $cdnLinks ?: null,
                'hash' => $hash,
            ]
        );
    }

    /**
     * Recompile all pages that use a given block.
     */
    public function recompileForBlock(int $blockId): void
    {
        $pageIds = PageBlock::where('block_id', $blockId)->pluck('page_id')->unique();

        foreach ($pageIds as $pageId) {
            $page = Page::find($pageId);
            if ($page) {
                $this->compile($page);
            }
        }
    }

    /**
     * Recompile all pages that use a given template.
     */
    public function recompileForTemplate(int $templatePageId): void
    {
        Page::where('template_page_id', $templatePageId)->each(function ($page) {
            $this->compile($page);
        });
    }

    /**
     * Recompile all pages that use a given component.
     */
    public function recompileForComponent(string $componentSlug): void
    {
        $pageIds = collect();

        // 1. Check block templates for component references
        Block::all()->each(function ($block) use ($componentSlug, &$pageIds) {
            $blockPath = $this->blockRenderer->resolveBlockPath($block);
            if (!$blockPath) return;

            $templateFile = $blockPath . '/template.blade.php';
            if (!file_exists($templateFile)) return;

            $content = file_get_contents($templateFile);
            $refs = $this->parseComponentReferences($content);
            if (!in_array($componentSlug, $refs)) return;

            $pageIds = $pageIds->merge(PageBlock::where('block_id', $block->id)->pluck('page_id'));
        });

        // 2. Check template page templates for component references
        TemplatePage::all()->each(function ($template) use ($componentSlug, &$pageIds) {
            $templateFile = storage_path('cms/templates/' . basename($template->slug) . '/template.blade.php');
            if (!file_exists($templateFile)) return;

            $content = file_get_contents($templateFile);
            $refs = $this->parseComponentReferences($content);
            if (!in_array($componentSlug, $refs)) return;

            $pageIds = $pageIds->merge(Page::where('template_page_id', $template->id)->pluck('id'));
        });

        // 3. Recompile affected pages
        foreach ($pageIds->unique() as $pageId) {
            $page = Page::find($pageId);
            if ($page) {
                $this->compile($page);
            }
        }
    }

    /**
     * Recompile all pages that use a given library.
     */
    public function recompileForLibrary(int $libraryId): void
    {
        $library = Library::with(['blocks', 'templatePages'])->find($libraryId);
        if (!$library) return;

        $pageIds = collect();

        foreach ($library->blocks as $block) {
            $pageIds = $pageIds->merge(PageBlock::where('block_id', $block->id)->pluck('page_id'));
        }

        foreach ($library->templatePages as $template) {
            $pageIds = $pageIds->merge(Page::where('template_page_id', $template->id)->pluck('id'));
        }

        foreach ($pageIds->unique() as $pageId) {
            $page = Page::find($pageId);
            if ($page) {
                $this->compile($page);
            }
        }
    }

    /**
     * Compile assets for ALL published pages.
     *
     * @return array{compiled: int, errors: int}
     */
    public function compileAll(): array
    {
        $compiled = 0;
        $errors = 0;

        Page::where('status', 1)->chunk(50, function ($pages) use (&$compiled, &$errors) {
            foreach ($pages as $page) {
                try {
                    $this->compile($page);
                    $compiled++;
                } catch (\Throwable $e) {
                    $errors++;
                    \Illuminate\Support\Facades\Log::error("Asset compile failed for page {$page->id}: {$e->getMessage()}");
                }
            }
        });

        return ['compiled' => $compiled, 'errors' => $errors];
    }

    /**
     * Delete all compiled assets.
     */
    public function cleanAll(): void
    {
        $dir = storage_path('app/public/cms/assets/pages');
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
        PageAsset::truncate();
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    protected function collectLibraries(Page $page): \Illuminate\Support\Collection
    {
        $libraryIds = collect();

        // From page blocks
        $blocks = PageBlock::where('page_id', $page->id)->with('block.libraries')->get();
        foreach ($blocks as $pb) {
            if ($pb->block) {
                $libraryIds = $libraryIds->merge($pb->block->libraries->pluck('id'));
            }
        }

        // From template itself
        if ($page->template_page_id) {
            $page->loadMissing('templatePage.libraries');
            if ($page->templatePage) {
                $libraryIds = $libraryIds->merge($page->templatePage->libraries->pluck('id'));
            }
        }

        return Library::whereIn('id', $libraryIds->unique())->active()->orderBy('sort_order')->get();
    }

    protected function collectBlockSlugs(Page $page): array
    {
        $slugs = [];

        $pageBlocks = PageBlock::where('page_id', $page->id)->with('block')->get();
        foreach ($pageBlocks as $pb) {
            if ($pb->block) {
                $slugs[$pb->block->slug] = $pb->block;
            }
        }

        return $slugs;
    }

    /**
     * Extract component slugs used in block/template Blade templates.
     */
    protected function extractComponentSlugs(array $blockSlugs, Page $page): array
    {
        $componentSlugs = [];

        // 1. Parse block templates
        foreach ($blockSlugs as $slug => $block) {
            $blockPath = $this->blockRenderer->resolveBlockPath($block);
            if ($blockPath) {
                $templateFile = $blockPath . '/template.blade.php';
                if (file_exists($templateFile)) {
                    $content = file_get_contents($templateFile);
                    $componentSlugs = array_merge($componentSlugs, $this->parseComponentReferences($content));
                }
            }
        }

        // 2. Parse template page template
        if ($page->template_page_id) {
            $page->loadMissing('templatePage');
            if ($page->templatePage) {
                $templateFile = storage_path('cms/templates/' . basename($page->templatePage->slug) . '/template.blade.php');
                if (file_exists($templateFile)) {
                    $content = file_get_contents($templateFile);
                    $componentSlugs = array_merge($componentSlugs, $this->parseComponentReferences($content));
                }
            }
        }

        return array_unique($componentSlugs);
    }

    /**
     * Parse Blade content for <x-cms::slug> component references.
     */
    protected function parseComponentReferences(string $content): array
    {
        if (preg_match_all('/<x-cms::([a-z0-9](?:[a-z0-9-]*[a-z0-9])?)[\s\/>]/i', $content, $matches)) {
            return $matches[1];
        }

        return [];
    }

    protected function buildCss(\Illuminate\Support\Collection $libraries, array $blockSlugs, Page $page, array $componentSlugs = []): string
    {
        $parts = [];

        // 1. Template styles
        if ($page->template_page_id) {
            $page->loadMissing('templatePage');
            if ($page->templatePage) {
                $templateCss = $this->blockRenderer->compileTemplateStyles($page->templatePage);
                if ($templateCss) {
                    $parts[] = "/* Template: {$page->templatePage->slug} */";
                    $parts[] = $templateCss;
                }
            }
        }

        // 2. Component styles (deduplicated by slug)
        foreach ($componentSlugs as $compSlug) {
            $css = $this->blockRenderer->compileComponentStyles($compSlug);
            if ($css) {
                $parts[] = "/* Component: {$compSlug} */";
                $parts[] = $css;
            }
        }

        // 3. Block styles (deduplicated by slug)
        foreach ($blockSlugs as $slug => $block) {
            $css = $this->blockRenderer->compileStyles($block);
            if ($css) {
                $parts[] = "/* Block: {$slug} */";
                $parts[] = $css;
            }
        }

        return implode("\n", $parts);
    }

    protected function buildJs(\Illuminate\Support\Collection $libraries, array $blockSlugs, Page $page, array $componentSlugs = []): string
    {
        $parts = [];

        // 1. Templite Runtime
        $parts[] = $this->getRuntime();

        // 2. Template script
        if ($page->template_page_id) {
            $page->loadMissing('templatePage');
            if ($page->templatePage) {
                $templateJs = $this->blockRenderer->getTemplateScript($page->templatePage);
                if ($templateJs && trim($templateJs) !== '') {
                    $parts[] = "/* Template: {$page->templatePage->slug} */";
                    $parts[] = $templateJs;
                }
            }
        }

        // 3. Component scripts (IIFE-wrapped, deduplicated)
        foreach ($componentSlugs as $compSlug) {
            $script = $this->blockRenderer->getComponentScript($compSlug);
            if ($script && trim($script) !== '') {
                $parts[] = "/* Component: {$compSlug} */";
                $parts[] = "(function() {";
                $parts[] = $script;
                $parts[] = "})();";
            }
        }

        // 4. Block scripts (wrapped in registerBlock, deduplicated)
        foreach ($blockSlugs as $slug => $block) {
            $blockPath = $this->blockRenderer->resolveBlockPath($block);
            if ($blockPath) {
                $scriptFile = $blockPath . '/script.js';
                if (file_exists($scriptFile)) {
                    $script = file_get_contents($scriptFile);
                    if (trim($script) !== '') {
                        $parts[] = "/* Block: {$slug} */";
                        if ($block->no_wrapper) {
                            // Без обёртки — IIFE без привязки к элементу
                            $parts[] = "(function() {";
                            $parts[] = $script;
                            $parts[] = "})();";
                        } else {
                            $parts[] = "Templite.registerBlock('" . addslashes($slug) . "', function(el) {";
                            $parts[] = $script;
                            $parts[] = '});';
                        }
                    }
                }
            }
        }

        // 5. Auto-init
        $parts[] = "document.addEventListener('DOMContentLoaded', function() { Templite.init(); });";

        return implode("\n", $parts);
    }

    protected function collectCdnLinks(\Illuminate\Support\Collection $libraries): array
    {
        $links = [];
        foreach ($libraries as $lib) {
            if ($lib->load_strategy === 'cdn') {
                if ($lib->css_cdn) {
                    $links[] = ['type' => 'css', 'url' => $lib->css_cdn];
                }
                if ($lib->js_cdn) {
                    $links[] = ['type' => 'js', 'url' => $lib->js_cdn];
                }
            } elseif ($lib->load_strategy === 'local') {
                if ($lib->css_file) {
                    $links[] = ['type' => 'css', 'url' => asset('storage/' . ltrim($lib->css_file, '/'))];
                }
                if ($lib->js_file) {
                    $links[] = ['type' => 'js', 'url' => asset('storage/' . ltrim($lib->js_file, '/'))];
                }
            }
        }
        return $links;
    }

    protected function getRuntime(): string
    {
        return <<<'JS'
/* Templite Runtime */
window.Templite = window.Templite || {
  _blocks: {},
  registerBlock: function(slug, fn) {
    this._blocks[slug] = fn;
  },
  initBlock: function(slug, el) {
    if (this._blocks[slug]) {
      try {
        this._blocks[slug](el);
      } catch (e) {
        console.error('[Templite] Block "' + slug + '" init error:', e);
      }
    }
  },
  init: function() {
    document.querySelectorAll('[data-block]').forEach(function(el) {
      Templite.initBlock(el.dataset.block, el);
    });
  }
};
JS;
    }

    protected function ensureDirectory(string $path): void
    {
        if (!File::isDirectory($path)) {
            File::makeDirectory($path, 0755, true);
        }
    }

    protected function cleanDirectory(string $path): void
    {
        if (File::isDirectory($path)) {
            foreach (File::files($path) as $file) {
                File::delete($file);
            }
        }
    }
}
