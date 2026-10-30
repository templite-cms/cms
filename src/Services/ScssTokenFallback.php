<?php

namespace Templite\Cms\Services;

/**
 * Подстановка значений по умолчанию в CSS-переменные дизайн-системы.
 *
 * Стили блоков попадают на публичный сайт, где токены админки не определены:
 * `rgb(var(--color-text-primary))` без значения по умолчанию даёт невалидный цвет,
 * и блок теряет оформление. Авторы блоков (в том числе AI-ассистент) регулярно
 * переносят эти токены из образцов, поэтому значение подставляется при сохранении.
 *
 * Переменная остаётся переменной: если сайт определит свою палитру, она победит.
 */
class ScssTokenFallback
{
    /**
     * Светлая тема дизайн-системы CMS. Значения — RGB-триплеты,
     * потому что токены используются внутри `rgb(...)`.
     *
     * @var array<string,string>
     */
    private const DEFAULTS = [
        '--color-surface-page' => '248 250 252',
        '--color-surface-card' => '255 255 255',
        '--color-surface-muted' => '241 245 249',
        '--color-surface-hover' => '241 245 249',
        '--color-surface-active' => '238 244 255',
        '--color-text-primary' => '15 23 42',
        '--color-text-secondary' => '71 85 105',
        '--color-text-muted' => '100 116 139',
        '--color-border-light' => '226 232 240',
        '--color-border-default' => '203 213 225',
    ];

    /**
     * Дописать значения по умолчанию в известные токены.
     * Переменные с уже указанным запасным значением и незнакомые не трогаются.
     */
    public function apply(string $scss): string
    {
        if ($scss === '') {
            return $scss;
        }

        return (string) preg_replace_callback(
            '/var\(\s*(--[a-z0-9-]+)\s*\)/i',
            static function (array $m): string {
                $default = self::DEFAULTS[strtolower($m[1])] ?? null;

                return $default === null ? $m[0] : "var({$m[1]}, {$default})";
            },
            $scss
        );
    }
}
