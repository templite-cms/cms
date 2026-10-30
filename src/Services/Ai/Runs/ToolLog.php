<?php

namespace Templite\Cms\Services\Ai\Runs;

/**
 * Краткая запись действий агента. Попадает в историю для модели: без неё
 * в следующем ходе модель не знает, что сама делала в прошлом.
 */
final class ToolLog
{
    /** Потолок журнала одного хода в контексте модели. */
    private const MAX_TOTAL = 1500;

    private const HINT_KEYS = '/(^id$|_id$|slug|alias|^key$|^name$|^title$)/';

    /** `update_block(page_block_id=12, block_slug=hero)` — только опознавательные аргументы. */
    public static function call(string $name, array $args): string
    {
        $hints = [];

        foreach ($args as $key => $value) {
            if (count($hints) >= 3) {
                break;
            }

            if (is_scalar($value) && preg_match(self::HINT_KEYS, (string) $key)) {
                $hints[] = $key.'='.mb_substr((string) $value, 0, 40);
            }
        }

        return $name.'('.implode(', ', $hints).')';
    }

    /**
     * Аргументы для журнала событий: скаляры коротко, вложенное — пометкой.
     * Код блока целиком в журнале никому не нужен.
     *
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    public static function compactArgs(array $args): array
    {
        $out = [];

        foreach ($args as $key => $value) {
            $out[$key] = match (true) {
                is_string($value) => mb_substr($value, 0, 200),
                is_scalar($value), $value === null => $value,
                default => '…',
            };
        }

        return $out;
    }

    /** @param array<int,string> $entries */
    public static function render(array $entries): string
    {
        $text = implode('; ', $entries);

        return mb_strlen($text) > self::MAX_TOTAL ? mb_substr($text, 0, self::MAX_TOTAL).'…' : $text;
    }
}
