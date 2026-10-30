<?php

namespace Templite\Cms\Services;

use Templite\Cms\Models\Block;

/**
 * Предупреждения по коду блока. Ничего не запрещает — подсказывает, где блок
 * поведёт себя не так, как ждёт автор: стили утекут на страницу, скрипт
 * обработает только первый экземпляр, метка ляжет не на тот элемент.
 *
 * Блок не оборачивается в контейнер, поэтому изоляция стилей держится на БЭМ
 * с префиксом slug, а скрипт получает корень блока как el по метке data-block.
 */
class BlockCodeLinter
{
    /** Сколько примеров селекторов показывать в одном предупреждении */
    protected const MAX_EXAMPLES = 5;

    public function __construct(
        protected BlockRenderer $blockRenderer,
    ) {}

    /**
     * @return string[]
     */
    public function lint(Block $block, string $template, string $style, string $script): array
    {
        return array_values(array_filter([
            ...$this->lintTemplate($block, $template),
            ...$this->lintStyle($block, $style),
            ...$this->lintScript($block, $script),
        ]));
    }

    // ------------------------------------------------------------------
    // Template
    // ------------------------------------------------------------------

    /**
     * @return string[]
     */
    public function lintTemplate(Block $block, string $template): array
    {
        if (trim($template) === '') {
            return [];
        }

        $roots = $this->countRoots($template);
        $manual = str_contains($template, 'data-block=') || str_contains($template, '$block->marker');

        if ($roots > 1 && $block->auto_marker && !$manual) {
            return ["Шаблон: несколько корневых элементов. Метка data-block встанет только на первый — скрипт получит его как el, а остальные останутся без неё. Оберните вёрстку в один элемент или поставьте метку вручную: <section {{ \$block->marker }}>."];
        }
        if ($roots > 1) {
            return ["Шаблон: несколько корневых элементов. Блок выведется как есть; у каждого корня — свой класс с префиксом «{$block->slug}», чтобы стили не задели остальную страницу."];
        }

        return [];
    }

    /**
     * Число корневых элементов шаблона. Ветки @if/@else считаются вариантами
     * одного места (берётся максимум), элемент в цикле на верхнем уровне — несколько.
     */
    protected function countRoots(string $template): int
    {
        $s = preg_replace('/\{\{--.*?--\}\}/s', '', $template);
        $s = preg_replace('/@php\b.*?@endphp/s', '', $s);
        $s = preg_replace('/<!--.*?-->/s', '', $s);
        $s = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', 'x', $s);
        $s = preg_replace('/<(script|style)\b.*?<\/\1>/si', '<$1/>', $s);

        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

        // Кадр: [тип, счётчик текущей ветки, максимум по веткам]
        $frames = [['root', 0, 0]];
        $depth = 0;

        $pattern = '/@(if|unless|isset|empty|foreach|for|while|elseif|else|endif|endunless|endisset|endempty|endforeach|endfor|endwhile)\b(\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?'
            . '|<\/([a-zA-Z][\w:.-]*)\s*>'
            . '|<([a-zA-Z][\w:.-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/';

        preg_match_all($pattern, $s, $tokens, PREG_SET_ORDER);

        foreach ($tokens as $t) {
            if (!empty($t[1])) {
                if ($depth > 0) {
                    continue;
                }
                $dir = $t[1];
                $top = count($frames) - 1;
                if (in_array($dir, ['if', 'unless', 'isset', 'empty'], true)) {
                    $frames[] = ['if', 0, 0];
                } elseif (in_array($dir, ['foreach', 'for', 'while'], true)) {
                    $frames[] = ['loop', 0, 0];
                } elseif (in_array($dir, ['elseif', 'else'], true) && $frames[$top][0] === 'if') {
                    $frames[$top][2] = max($frames[$top][2], $frames[$top][1]);
                    $frames[$top][1] = 0;
                } elseif (str_starts_with($dir, 'end') && $top > 0) {
                    [$type, $current, $max] = array_pop($frames);
                    $count = $type === 'loop' ? ($current > 0 ? 2 : 0) : max($max, $current);
                    $frames[count($frames) - 1][1] += $count;
                }
                continue;
            }

            if (!empty($t[3])) {
                $depth = max(0, $depth - 1);
                continue;
            }

            $tag = strtolower($t[4]);
            if ($depth === 0) {
                $frames[count($frames) - 1][1]++;
            }
            if ($t[6] !== '/' && !in_array($tag, $void, true)) {
                $depth++;
            }
        }

        // Незакрытые директивы — сворачиваем как есть
        while (count($frames) > 1) {
            [$type, $current, $max] = array_pop($frames);
            $frames[count($frames) - 1][1] += $type === 'loop' ? ($current > 0 ? 2 : 0) : max($max, $current);
        }

        return $frames[0][1];
    }

    // ------------------------------------------------------------------
    // Style
    // ------------------------------------------------------------------

    /**
     * @return string[]
     */
    public function lintStyle(Block $block, string $style): array
    {
        if (trim($style) === '') {
            return [];
        }

        $css = $this->blockRenderer->compileStylesFromString($style, $block->slug);
        if ($css === null || str_starts_with(trim($css), '/* SCSS Error')) {
            return [];
        }

        $slug = preg_quote($block->slug, '/');
        $ownSelector = '/\.' . $slug . '(?:__|--|(?![\w-]))|\[data-block="' . $slug . '"\]/';

        $foreign = [];
        $global = [];
        $keyframes = [];
        $fontFace = false;

        foreach ($this->collectSelectors($css, $keyframes, $fontFace) as $selector) {
            if (preg_match($ownSelector, $selector)) {
                continue;
            }
            if (preg_match('/(?:^|[\s>+~,(])(?::root|html|body)(?![\w-])/', $selector)) {
                $global[$selector] = true;
            } else {
                $foreign[$selector] = true;
            }
        }

        $warnings = [];
        if ($global) {
            $warnings[] = 'Стили: правила для ' . $this->examples(array_keys($global))
                . ' действуют на весь сайт. Глобальные стили и переменные задаются в шаблоне страницы, а не в блоке.';
        }
        if ($foreign) {
            $warnings[] = 'Стили: селекторы без префикса «' . $block->slug . '» применятся ко всей странице — '
                . $this->examples(array_keys($foreign))
                . ". Называйте классы по БЭМ от slug (.{$block->slug}, .{$block->slug}__элемент, .{$block->slug}--модификатор) или начинайте селектор с :scope (корень блока).";
        }
        $badKeyframes = array_filter($keyframes, fn ($name) => !str_starts_with($name, $block->slug));
        if ($badKeyframes) {
            $warnings[] = 'Стили: имена анимаций общие для всей страницы — ' . $this->examples(array_values($badKeyframes))
                . ". Начинайте их с «{$block->slug}-», чтобы не совпасть с анимацией другого блока.";
        }
        if ($fontFace) {
            $warnings[] = 'Стили: @font-face в блоке подключает шрифт для всего сайта. Шрифты подключаются библиотекой или в шаблоне страницы.';
        }

        return $warnings;
    }

    /**
     * Селекторы правил из скомпилированного CSS (с заходом в @media, @supports и т.п.).
     *
     * @param string[] $keyframes имена @keyframes
     * @return string[]
     */
    protected function collectSelectors(string $css, array &$keyframes, bool &$fontFace): array
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        $selectors = [];
        $length = strlen($css);
        $buffer = '';
        $skipDepth = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];

            if ($skipDepth > 0) {
                if ($char === '{') {
                    $skipDepth++;
                } elseif ($char === '}') {
                    $skipDepth--;
                }
                continue;
            }

            if ($char === '{') {
                $prelude = trim($buffer);
                $buffer = '';

                if (str_starts_with($prelude, '@')) {
                    if (preg_match('/^@(?:-\w+-)?keyframes\s+([\w-]+)/', $prelude, $m)) {
                        $keyframes[] = $m[1];
                        $skipDepth = 1;
                    } elseif (str_starts_with($prelude, '@font-face')) {
                        $fontFace = true;
                        $skipDepth = 1;
                    } elseif (!preg_match('/^@(media|supports|container|layer|scope|document)\b/', $prelude)) {
                        $skipDepth = 1;
                    }
                    // Условные at-rules — просто заходим внутрь
                    continue;
                }

                foreach ($this->splitSelectorList($prelude) as $selector) {
                    $selectors[] = $selector;
                }
                // Тело правила — объявления, селекторов там нет
                $skipDepth = 1;
                continue;
            }

            if ($char === '}' || $char === ';') {
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        return $selectors;
    }

    /**
     * @return string[]
     */
    protected function splitSelectorList(string $list): array
    {
        $parts = [];
        $depth = 0;
        $current = '';
        foreach (str_split($list) as $char) {
            if ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = trim($current);

        return array_filter($parts, fn ($p) => $p !== '');
    }

    // ------------------------------------------------------------------
    // Script
    // ------------------------------------------------------------------

    /**
     * @return string[]
     */
    public function lintScript(Block $block, string $script): array
    {
        if (trim($script) === '' || !$block->auto_marker) {
            return [];
        }

        if (preg_match('/document\.(querySelector|getElementById|getElementsByClassName)\s*\(/', $script)
            && !preg_match('/\bel\s*\.\s*(querySelector|querySelectorAll|closest|matches|classList)\b|\bel\b\s*\)/', $script)) {
            return ['Скрипт: поиск по всему document. Скрипт вызывается для каждого экземпляра блока и получает его корень как el — ищите внутри него (el.querySelector(...)), иначе при двух одинаковых блоках на странице оба вызова найдут первый.'];
        }

        return [];
    }

    /**
     * @param string[] $items
     */
    protected function examples(array $items): string
    {
        $shown = array_slice($items, 0, self::MAX_EXAMPLES);
        $rest = count($items) - count($shown);

        return implode(', ', $shown) . ($rest > 0 ? " и ещё {$rest}" : '');
    }
}
