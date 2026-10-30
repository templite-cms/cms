<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Collection;
use Templite\Cms\Models\LibraryFile;

/**
 * Единственное место, где библиотеки превращаются в теги подключения.
 * Все превью и компиляция ассетов страницы ходят сюда.
 */
class LibraryAssetBuilder
{
    /**
     * Плоский список подключений. Библиотеки — в порядке sort_order,
     * файлы внутри библиотеки — в порядке своего sort_order.
     * Неактивные библиотеки пропускаются.
     *
     * Ничьи по sort_order разрешаются по id: у библиотек sort_order по умолчанию
     * нулевой, а порядок подключения js нагружен смыслом (jQuery до плагина),
     * поэтому результат обязан быть детерминированным и не зависеть от того,
     * в каком порядке вызывающий собрал коллекцию.
     *
     * Дедупликация двойная: библиотека учитывается один раз по id, а ссылка —
     * по ключу из dedupKey(). Побеждает первое вхождение, то есть флаги берутся
     * у той библиотеки, которая идёт раньше по sort_order.
     *
     * @param  Collection  $libraries  коллекция моделей Library
     * @return array<int, array{type: 'js'|'css', url: string, loading: ?string, media: ?string}>
     */
    public function collect(Collection $libraries): array
    {
        $links = [];
        $seenLibraries = [];
        $seenLinks = [];

        $order = [['sort_order', 'asc'], ['id', 'asc']];

        foreach ($libraries->where('active', true)->sortBy($order) as $library) {
            if (isset($seenLibraries[$library->id])) {
                continue;
            }
            $seenLibraries[$library->id] = true;

            $library->loadMissing('files');

            // Связь files() уже отсортирована в SQL — сортировка здесь нужна
            // лишь на случай, если коллекцию связи собрали вручную.
            foreach ($library->files->sortBy($order) as $file) {
                // Неизвестный тип ни один из методов ниже не выведет, но он
                // осел бы в page_assets.cdn_links и влиял на hash пересборки.
                if (!in_array($file->type, LibraryFile::TYPES, true)) {
                    continue;
                }

                $url = $file->resolvedUrl();

                if ($url === '') {
                    continue;
                }

                $entry = [
                    'type' => $file->type,
                    'url' => $url,
                    'loading' => $file->type === 'js' ? $file->loading : null,
                    'media' => $file->type === 'css' ? $file->media : null,
                ];

                $key = $this->dedupKey($entry);

                if (isset($seenLinks[$key])) {
                    continue;
                }
                $seenLinks[$key] = true;

                $links[] = $entry;
            }
        }

        return $links;
    }

    /**
     * Теги <link> для <head> из результата collect().
     */
    public function cssTags(array $links): string
    {
        $html = '';

        foreach ($links as $link) {
            if (($link['type'] ?? null) !== 'css') {
                continue;
            }

            $media = $link['media'] ?? null;

            $html .= '<link rel="stylesheet" href="' . e($link['url']) . '"'
                . (filled($media) ? ' media="' . e($media) . '"' : '')
                . '>' . "\n";
        }

        return $html;
    }

    /**
     * Теги <script> для конца <body> из результата collect().
     */
    public function jsTags(array $links): string
    {
        $html = '';

        foreach ($links as $link) {
            if (($link['type'] ?? null) !== 'js') {
                continue;
            }

            $html .= '<script src="' . e($link['url']) . '"'
                . $this->loadingAttribute($link['loading'] ?? null)
                . '></script>' . "\n";
        }

        return $html;
    }

    /**
     * Готовая разметка для набора библиотек.
     *
     * @return array{head: string, body: string}
     */
    public function htmlFor(Collection $libraries): array
    {
        $links = $this->collect($libraries);

        return [
            'head' => $this->cssTags($links),
            'body' => $this->jsTags($links),
        ];
    }

    /**
     * Ключ, по которому подключение считается уже добавленным.
     *
     * Для js — только url. Один и тот же скрипт с defer и без — это не два
     * подключения, а ошибка конфигурации: браузер загрузил бы файл дважды,
     * а второй экземпляр затёр бы глобали первого.
     *
     * Для css в ключ входит media. Одна и та же таблица стилей с media="print"
     * и с media="screen" применяется в разных условиях — это два законных
     * подключения, схлопывать их нельзя.
     *
     * @param  array{type: 'js'|'css', url: string, loading: ?string, media: ?string}  $entry
     */
    protected function dedupKey(array $entry): string
    {
        return $entry['type'] === 'css'
            ? 'css|' . $entry['url'] . '|' . ($entry['media'] ?? '')
            : 'js|' . $entry['url'];
    }

    /**
     * Атрибут способа загрузки js-файла. Пустая строка — обычный синхронный script.
     */
    protected function loadingAttribute(?string $loading): string
    {
        return match ($loading) {
            'module' => ' type="module"',
            'defer' => ' defer',
            'async' => ' async',
            default => '',
        };
    }
}
