<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Templite\Cms\Contracts\BlockRendererInterface;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Support\FieldsBag;
use ScssPhp\ScssPhp\Compiler as ScssCompiler;

class BlockRenderer implements BlockRendererInterface
{
    protected ?ScssCompiler $scssCompiler = null;

    public function __construct(
        protected BlockRegistry $blockRegistry,
        protected ComponentRegistry $componentRegistry,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function render(
        Block $block,
        array $fields,
        array $actions,
        ?Page $page,
        array $global,
    ): string {
        $blockPath = $this->resolveBlockPath($block);

        if (!$blockPath) {
            return "<!-- Block '{$block->slug}' not found -->";
        }

        $templateFile = $blockPath . '/template.blade.php';

        if (!file_exists($templateFile)) {
            return "<!-- Block '{$block->slug}' template not found -->";
        }

        // Валидация шаблона при рендере (защита от подмены файлов на диске)
        $templateContent = file_get_contents($templateFile);
        $validator = new BladeSecurityValidator();
        $violations = $validator->validate($templateContent);

        if (!empty($violations)) {
            report(new \Templite\Cms\Exceptions\UnsafeTemplateException(
                "Block '{$block->slug}' template contains unsafe constructs: " . implode(', ', $violations),
                $violations
            ));

            return "<!-- Block '{$block->slug}' blocked: unsafe template -->";
        }

        // Регистрируем Blade namespace для блока
        $namespace = 'block_' . $block->slug;
        View::addNamespace($namespace, $blockPath);

        // 5 переменных — каждая в своём namespace, без array_merge
        $variables = [
            'fields'  => FieldsBag::wrap($fields),
            'actions' => $actions,
            'page'    => $page,
            'global'  => $global,
            'block'   => $block,
        ];

        // Рендерим шаблон с защитой от ошибок в пользовательских шаблонах
        try {
            $html = view("{$namespace}::template", $variables)->render();
        } catch (\Throwable $e) {
            report($e);

            if (config('cms.dev_mode', false)) {
                return "<!-- Block '{$block->slug}' error: " . e($e->getMessage()) . " -->";
            }

            return "<!-- Block '{$block->slug}' render error -->";
        }

        // Если no_wrapper — отдаём HTML как есть, без обёртки
        if ($block->no_wrapper) {
            return $html;
        }

        // Оборачиваем в контейнер с data-block-id
        $blockId = uniqid($block->slug . '-');
        $output = "<div class=\"cms-block cms-block--{$block->slug}\" data-block=\"{$block->slug}\" data-block-id=\"{$blockId}\">\n";
        $output .= $html;
        $output .= "\n</div>";

        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function compileStyles(Block $block): ?string
    {
        $blockPath = $this->resolveBlockPath($block);

        if (!$blockPath) {
            return null;
        }

        $scssFile = $blockPath . '/style.scss';
        $cssFile = $blockPath . '/style.css';

        // Если есть готовый CSS
        if (file_exists($cssFile) && !file_exists($scssFile)) {
            return file_get_contents($cssFile);
        }

        // Если есть SCSS -- компилируем
        if (file_exists($scssFile)) {
            return $this->compileScssToCss($scssFile, $block->slug, (bool) $block->no_wrapper);
        }

        return null;
    }

    /**
     * {@inheritdoc}
     */
    public function resolveBlockPath(Block $block): ?string
    {
        // 1. Проверяем реестр (app > storage > vendor)
        $registryEntry = $this->blockRegistry->find($block->slug);
        if ($registryEntry && isset($registryEntry['path'])) {
            return $registryEntry['path'];
        }

        // 2. Проверяем app/Blocks/{slug}
        $appPath = app_path('Blocks/' . basename($block->slug));
        if (is_dir($appPath)) {
            return $appPath;
        }

        // 3. Проверяем storage/cms/blocks/{slug}
        $storagePath = storage_path('cms/blocks/' . basename($block->slug));
        if (is_dir($storagePath)) {
            return $storagePath;
        }

        // 4. Проверяем path из БД
        if ($block->path) {
            $customPath = base_path($block->path);
            if (is_dir($customPath)) {
                return $customPath;
            }
        }

        return null;
    }

    /**
     * Компилировать стили шаблона (глобальные, без namespace).
     */
    public function compileTemplateStyles(TemplatePage $template): ?string
    {
        $path = storage_path('cms/templates/' . basename($template->slug));

        $scssFile = $path . '/style.scss';
        $cssFile = $path . '/style.css';

        if (file_exists($cssFile) && !file_exists($scssFile)) {
            return file_get_contents($cssFile);
        }

        if (file_exists($scssFile)) {
            try {
                $compiler = $this->getScssCompiler();
                $scss = file_get_contents($scssFile);
                $result = $compiler->compileString($scss);
                return $result->getCss();
            } catch (\Throwable $e) {
                if (config('cms.dev_mode', false)) {
                    return "/* Template SCSS Error: {$e->getMessage()} */";
                }
                return null;
            }
        }

        return null;
    }

    /**
     * Получить JS-скрипт шаблона.
     */
    public function getTemplateScript(TemplatePage $template): ?string
    {
        $path = storage_path('cms/templates/' . basename($template->slug) . '/script.js');
        return file_exists($path) ? file_get_contents($path) : null;
    }

    /**
     * Разрешить путь к директории компонента (app > storage > vendor).
     */
    public function resolveComponentPath(string $slug): ?string
    {
        // 1. Проверяем реестр (app > storage > vendor)
        $registryEntry = $this->componentRegistry->find($slug);
        if ($registryEntry && isset($registryEntry['path'])) {
            return $registryEntry['path'];
        }

        // 2. Проверяем app/View/Components/Cms/{slug}
        $appPath = app_path('View/Components/Cms/' . basename($slug));
        if (is_dir($appPath)) {
            return $appPath;
        }

        // 3. Проверяем storage/cms/components/{slug}
        $storagePath = storage_path('cms/components/' . basename($slug));
        if (is_dir($storagePath)) {
            return $storagePath;
        }

        return null;
    }

    /**
     * Компилировать стили компонента.
     */
    public function compileComponentStyles(string $slug): ?string
    {
        $compPath = $this->resolveComponentPath($slug);

        if (!$compPath) {
            return null;
        }

        $scssFile = $compPath . '/style.scss';
        $cssFile = $compPath . '/style.css';

        if (file_exists($scssFile)) {
            return $this->compileComponentScssToCss($scssFile, $slug);
        }

        if (file_exists($cssFile)) {
            return $this->compileComponentScssToCss($cssFile, $slug);
        }

        return null;
    }

    /**
     * Получить JS-скрипт компонента.
     */
    public function getComponentScript(string $slug): ?string
    {
        $compPath = $this->resolveComponentPath($slug);

        if (!$compPath) {
            return null;
        }

        $path = $compPath . '/script.js';
        return file_exists($path) ? file_get_contents($path) : null;
    }

    /**
     * Компилировать SCSS/CSS компонента с namespace .cms-component--{slug}.
     */
    protected function compileComponentScssToCss(string $scssFile, string $slug): ?string
    {
        try {
            $compiler = $this->getScssCompiler();
            $scss = file_get_contents($scssFile);

            $wrappedScss = ".cms-component--{$slug} {\n{$scss}\n}";

            $result = $compiler->compileString($wrappedScss);

            return $result->getCss();
        } catch (\Throwable $e) {
            if (config('cms.dev_mode', false)) {
                return "/* Component SCSS Error ({$slug}): {$e->getMessage()} */";
            }

            return null;
        }
    }

    /**
     * Компилировать SCSS из строки в CSS.
     */
    public function compileStylesFromString(string $scss, string $blockSlug, bool $noWrapper = false): ?string
    {
        if (empty(trim($scss))) {
            return null;
        }

        try {
            $compiler = $this->getScssCompiler();

            if (!$noWrapper) {
                $scss = ".cms-block--{$blockSlug} {\n{$scss}\n}";
            }

            $result = $compiler->compileString($scss);

            return $result->getCss();
        } catch (\Throwable $e) {
            if (config('cms.dev_mode', false)) {
                return "/* SCSS Error: {$e->getMessage()} */";
            }

            return null;
        }
    }

    /**
     * Компилировать SCSS в CSS.
     */
    protected function compileScssToCss(string $scssFile, string $blockSlug, bool $noWrapper = false): ?string
    {
        try {
            $compiler = $this->getScssCompiler();
            $scss = file_get_contents($scssFile);

            // Если no_wrapper — компилируем SCSS без namespace-обёртки
            if (!$noWrapper) {
                $scss = ".cms-block--{$blockSlug} {\n{$scss}\n}";
            }

            $result = $compiler->compileString($scss);

            return $result->getCss();
        } catch (\Throwable $e) {
            // В dev-режиме показываем ошибку
            if (config('cms.dev_mode', false)) {
                return "/* SCSS Error: {$e->getMessage()} */";
            }

            return null;
        }
    }

    /**
     * Рендерить preview-обёртку.
     * Проверяет кастомный шаблон в CmsConfig (ключ preview_wrapper),
     * затем fallback на vendor-шаблон cms::render.preview.
     */
    public function renderPreviewWrapper(array $data): string
    {
        $customTemplate = \Templite\Cms\Models\CmsConfig::getValue('preview_wrapper');

        if ($customTemplate) {
            // Валидация кастомного шаблона при рендере (защита от подмены в БД)
            BladeSecurityValidator::assertSafe($customTemplate);

            return Blade::render($customTemplate, $data);
        }

        return view('cms::render.preview', $data)->render();
    }

    /**
     * Получить SCSS-компилятор (lazy init).
     */
    protected function getScssCompiler(): ScssCompiler
    {
        if ($this->scssCompiler === null) {
            $this->scssCompiler = new ScssCompiler();
            $this->scssCompiler->setOutputStyle(
                config('app.debug') ? \ScssPhp\ScssPhp\OutputStyle::EXPANDED : \ScssPhp\ScssPhp\OutputStyle::COMPRESSED
            );
        }

        return $this->scssCompiler;
    }
}
