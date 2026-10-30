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

        return $this->applyMarker($html, $block);
    }

    /**
     * Повесить метку data-block="{slug}" на корневой элемент блока.
     *
     * Обёртки нет: корень — первый элемент шаблона, какой написал разработчик.
     * По метке рантайм находит экземпляры блока и передаёт их скрипту как el.
     * Метка не ставится, если её отключили у блока или она уже есть на корне
     * (вручную: <section {{ $block->marker }}> или data-block="slug").
     */
    public function applyMarker(string $html, Block $block): string
    {
        // Выключена или разработчик поставил метку сам (возможно, не на первый элемент)
        if (!$block->auto_marker || str_contains($html, 'data-block="' . e($block->slug) . '"')) {
            return $html;
        }

        // Первый открывающий тег, пропуская пробелы и HTML-комментарии
        if (!preg_match('/\A(?:\s|<!--.*?-->)*<([a-zA-Z][\w:-]*)([^>]*)>/s', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $attrs = $m[2][0];
        if (preg_match('/\sdata-block\s*=/i', $attrs)) {
            return $html;
        }

        // Вставляем сразу после имени тега: так не мешаем самозакрывающемуся «/>»
        $insertAt = $m[1][1] + strlen($m[1][0]);

        return substr($html, 0, $insertAt) . ' data-block="' . e($block->slug) . '"' . substr($html, $insertAt);
    }

    /**
     * Рантайм блоков — один для сайта и всех превью админки.
     *
     * Скрипт блока вызывается для каждого элемента с data-block="{slug}" и
     * получает его как el. Нет меток и автометка отключена — один раз с el = null.
     * Ошибка одного блока не мешает остальным. Подписка на DOMContentLoaded
     * внутри блока срабатывает: пока идёт инициализация, событие уже наступило,
     * и такой обработчик вызывается сразу (иначе он не сработал бы никогда).
     */
    public function runtimeScript(): string
    {
        return <<<'JS'
/* Templite Runtime */
window.Templite = window.Templite || {
  _blocks: {},
  registerBlock: function (slug, fn, options) {
    this._blocks[slug] = { fn: fn, autoMarker: !options || options.autoMarker !== false };
  },
  _run: function (slug, fn, el) {
    try {
      fn(el);
    } catch (e) {
      console.error('[Templite] Block "' + slug + '" init error:', e);
    }
  },
  init: function (root) {
    var self = this;
    var scope = root || document;
    var doc = document;
    var origAdd = doc.addEventListener;
    var winAdd = window.addEventListener;
    // DOMContentLoaded уже прошёл — подписки на него из блоков вызываем сразу
    var lateReady = function (add) {
      return function (type, listener) {
        if (type === 'DOMContentLoaded') {
          setTimeout(function () {
            typeof listener === 'function' ? listener.call(doc, new Event(type)) : listener.handleEvent(new Event(type));
          });
          return;
        }
        return add.apply(this, arguments);
      };
    };
    doc.addEventListener = lateReady(origAdd);
    window.addEventListener = lateReady(winAdd);
    try {
      Object.keys(self._blocks).forEach(function (slug) {
        var block = self._blocks[slug];
        var els = scope.querySelectorAll('[data-block="' + slug + '"]');
        if (els.length) {
          els.forEach(function (el) {
            if (el.__templiteInit) return;
            el.__templiteInit = true;
            self._run(slug, block.fn, el);
          });
        } else if (!block.autoMarker && !root && !block._ranWithoutEl) {
          block._ranWithoutEl = true;
          self._run(slug, block.fn, null);
        }
      });
    } finally {
      doc.addEventListener = origAdd;
      window.addEventListener = winAdd;
    }
  }
};
JS;
    }

    /**
     * Обернуть скрипт блока для рантайма: функция с el, своя область видимости.
     */
    public function wrapBlockScript(Block $block, string $script): string
    {
        $options = $block->auto_marker ? '' : ', { autoMarker: false }';

        return "/* Block: {$block->slug} */\n"
            . "Templite.registerBlock(" . json_encode($block->slug) . ", function (el) {\n"
            . $script
            . "\n}{$options});";
    }

    /**
     * Запуск рантайма: сразу, если DOM готов, иначе по DOMContentLoaded
     * (к нему уже выполнятся и библиотеки, подключённые с defer).
     */
    public function runtimeBootScript(): string
    {
        return "if (document.readyState === 'loading') {\n"
            . "  document.addEventListener('DOMContentLoaded', function () { Templite.init(); });\n"
            . "} else {\n"
            . "  Templite.init();\n"
            . "}";
    }

    /**
     * Готовый JS для превью: рантайм, скрипты блоков в обёртке и запуск —
     * так же, как на сайте.
     *
     * @param array<int, array{0: Block, 1: string}> $blockScripts пары [блок, код]
     */
    public function previewScript(array $blockScripts, string $before = ''): string
    {
        $parts = [$this->runtimeScript()];
        if (trim($before) !== '') {
            $parts[] = $before;
        }
        $seen = [];
        foreach ($blockScripts as [$block, $script]) {
            if (isset($seen[$block->slug]) || trim((string) $script) === '') {
                continue;
            }
            $seen[$block->slug] = true;
            $parts[] = $this->wrapBlockScript($block, $script);
        }
        $parts[] = $this->runtimeBootScript();

        return implode("\n", $parts);
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
            return $this->compileScssToCss($scssFile, $block->slug);
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
     * Компилировать SCSS/CSS компонента — как есть, без скоупа: компонент
     * вставляется тегом <x-cms::slug /> в произвольное место (в <nav>, в <head>),
     * изоляция — по БЭМ с префиксом slug, как и у блоков.
     */
    protected function compileComponentScssToCss(string $scssFile, string $slug): ?string
    {
        try {
            $compiler = $this->getScssCompiler();
            $scss = file_get_contents($scssFile);

            $result = $compiler->compileString($scss);

            return $result->getCss();
        } catch (\Throwable $e) {
            if (config('cms.dev_mode', false)) {
                return "/* Component SCSS Error ({$slug}): {$e->getMessage()} */";
            }

            return null;
        }
    }

    /**
     * Компилировать SCSS блока из строки в CSS.
     *
     * Стили блока не скоупятся обёрткой: изоляция — по БЭМ с префиксом slug
     * (проверяет BlockCodeLinter). :scope — синоним метки корня [data-block="{slug}"].
     */
    public function compileStylesFromString(string $scss, string $blockSlug): ?string
    {
        if (empty(trim($scss))) {
            return null;
        }

        try {
            $result = $this->getScssCompiler()->compileString($this->expandScope($scss, $blockSlug));

            return $result->getCss();
        } catch (\Throwable $e) {
            if (config('cms.dev_mode', false)) {
                return "/* SCSS Error: {$e->getMessage()} */";
            }

            return null;
        }
    }

    /**
     * Компилировать SCSS-файл блока в CSS.
     */
    protected function compileScssToCss(string $scssFile, string $blockSlug): ?string
    {
        return $this->compileStylesFromString(file_get_contents($scssFile), $blockSlug);
    }

    /**
     * :scope в стилях блока → [data-block="{slug}"] (корень с меткой).
     */
    public function expandScope(string $scss, string $blockSlug): string
    {
        return preg_replace('/:scope\b/', '[data-block="' . $blockSlug . '"]', $scss);
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
     * Parse Blade content for <x-cms::slug> component references.
     */
    public function parseComponentReferences(string $content): array
    {
        if (preg_match_all('/<x-cms::([a-z0-9](?:[a-z0-9-]*[a-z0-9])?)[\s\/>]/i', $content, $matches)) {
            return $matches[1];
        }

        return [];
    }

    /**
     * Collect compiled CSS and JS for components referenced in Blade content.
     *
     * @return array{css: string, js: string}
     */
    public function collectComponentAssets(string ...$bladeContents): array
    {
        $slugs = [];
        foreach ($bladeContents as $content) {
            $slugs = array_merge($slugs, $this->parseComponentReferences($content));
        }
        $slugs = array_unique($slugs);

        $css = '';
        $js = '';

        foreach ($slugs as $slug) {
            $compCss = $this->compileComponentStyles($slug);
            if ($compCss) {
                $css .= "/* Component: {$slug} */\n{$compCss}\n";
            }
            $compJs = $this->getComponentScript($slug);
            if ($compJs && trim($compJs) !== '') {
                $js .= "/* Component: {$slug} */\n(function() {\n{$compJs}\n})();\n";
            }
        }

        return ['css' => $css, 'js' => $js];
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
