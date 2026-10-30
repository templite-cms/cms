<?php

namespace Templite\Cms\Services\Ai\Settings;

/**
 * Текстовая карта вкладки настроек: колонки, их ширины, секции и вес секций
 * в полях. Нужна как обратная связь агенту — превью админского экрана на
 * сервере не отрендерить, а перекос («слева девять полей, справа одно»)
 * иначе замечает только человек.
 */
class SettingsLayoutMap
{
    /** @param array<string,mixed> $page вкладка из SettingsTree */
    public static function render(array $page): string
    {
        $columns = max(1, (int) ($page['columns'] ?? 1));
        $widths = $page['column_widths'] ?: array_fill(0, $columns, round(100 / $columns, 2));

        $cells = array_fill(0, $columns, []);
        $counts = array_fill(0, $columns, 0);
        $outOfRange = [];

        foreach ($page['sections'] ?? [] as $section) {
            $column = (int) ($section['column'] ?? 0);
            $fieldCount = count($section['fields'] ?? []);

            // Секция с номером колонки вне текущего диапазона — рассинхрон
            // (например, у вкладки уменьшили число колонок, а секция осталась
            // в старой). Раньше такая секция молча пропадала из карты: класс
            // существует ровно ради того, чтобы агент видел такие перекосы.
            if ($column < 0 || $column >= $columns) {
                $outOfRange[] = ['name' => $section['name'], 'column' => $column];

                continue;
            }

            $counts[$column] += $fieldCount;
            $cells[$column][] = $section['name'].' ('.$fieldCount.')';
        }

        $heads = [];
        $inner = [];

        for ($i = 0; $i < $columns; $i++) {
            $heads[$i] = 'Колонка '.($i + 1).' ('.($widths[$i] ?? 0).'%)';
            $inner[$i] = max(
                self::len($heads[$i]),
                ...array_map(fn ($line) => self::len($line) + 2, $cells[$i] ?: [''])
            );
        }

        // Фолбэк ?: [0] был не нужен: $cells всегда содержит $columns >= 1
        // элементов (array_fill выше), array_map никогда не вернёт [].
        $rows = max(array_map('count', $cells));
        $lines = [];

        $lines[] = 'Вкладка «'.$page['name'].'» — '.$columns.' '.self::pluralRu($columns, 'колонка', 'колонки', 'колонок').' ['.implode(' | ', array_map(fn ($w) => $w.'%', $widths)).']';
        $lines[] = '';
        $lines[] = self::join(array_map(fn ($i) => self::pad($heads[$i], $inner[$i]), range(0, $columns - 1)));
        $lines[] = self::join(array_map(fn ($i) => '┌'.str_repeat('─', $inner[$i]).'┐', range(0, $columns - 1)));

        for ($row = 0; $row < max(1, $rows); $row++) {
            $lines[] = self::join(array_map(function ($i) use ($cells, $inner, $row) {
                $text = $cells[$i][$row] ?? '';

                return '│ '.self::pad($text, $inner[$i] - 2).' │';
            }, range(0, $columns - 1)));
        }

        $lines[] = self::join(array_map(fn ($i) => '└'.str_repeat('─', $inner[$i]).'┘', range(0, $columns - 1)));
        $lines[] = '';
        // Баланс — только про видимые колонки: секции вне диапазона в счёт не идут,
        // у них своя строка ниже.
        $lines[] = 'баланс: '.implode(' / ', array_map(fn ($c) => $c.' '.self::pluralRu($c, 'поле', 'поля', 'полей'), $counts)).self::verdict($counts);

        if ($outOfRange) {
            $lines[] = 'вне раскладки: '.implode(', ', array_map(
                fn ($s) => '«'.$s['name'].'» (колонка '.($s['column'] + 1).')',
                $outOfRange
            )).' — у вкладки всего '.$columns.' '.self::pluralRu($columns, 'колонка', 'колонки', 'колонок').', '
                .(count($outOfRange) > 1 ? 'эти секции' : 'эта секция').' в админке не '
                .(count($outOfRange) > 1 ? 'показываются' : 'показывается');
        }

        return implode("\n", $lines);
    }

    /** @param array<int,string> $parts */
    private static function join(array $parts): string
    {
        return rtrim(implode('  ', $parts));
    }

    private static function pad(string $text, int $width): string
    {
        return $text.str_repeat(' ', max(0, $width - self::len($text)));
    }

    private static function len(string $text): int
    {
        return mb_strlen($text);
    }

    /**
     * Склонение числительного по стандартной идиоме остатков от 10 и 100.
     * Карту читает языковая модель (а иногда и человек в чате), «2 полей»
     * лишний шум в тексте, который и так им обоим приходится разбирать.
     */
    private static function pluralRu(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n);
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        if ($mod10 === 1) {
            return $one;
        }

        if ($mod10 >= 2 && $mod10 <= 4) {
            return $few;
        }

        return $many;
    }

    /** @param array<int,int> $counts */
    private static function verdict(array $counts): string
    {
        $max = max($counts);
        $min = min($counts);

        if ($max === 0) {
            return ' — вкладка пуста';
        }

        return $min > 0 && $max / max(1, $min) <= 2
            ? ' — ровно'
            : ' — перекос, стоит переложить секции';
    }
}
