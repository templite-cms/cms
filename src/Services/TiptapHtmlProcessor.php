<?php

namespace Templite\Cms\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Blade;
use Templite\Cms\Models\File;

class TiptapHtmlProcessor
{
    /**
     * Обработать HTML из tiptap-поля: заменить <img data-file-id="X">
     * на отрендеренный <x-cms::image>.
     */
    public function process(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        // Быстрая проверка: если нет data-file-id, возвращаем как есть
        if (strpos($html, 'data-file-id') === false) {
            return $html;
        }

        // Собрать все file_id из HTML
        $fileIds = $this->extractFileIds($html);

        if (empty($fileIds)) {
            return $html;
        }

        // Batch-загрузка файлов (1 SQL-запрос)
        $files = File::whereIn('id', $fileIds)->get()->keyBy('id');

        // Заменить <img> теги
        return $this->replaceImages($html, $files);
    }

    /**
     * Извлечь все file_id из HTML.
     */
    protected function extractFileIds(string $html): array
    {
        preg_match_all('/data-file-id=["\'](\d+)["\']/', $html, $matches);

        return array_unique(array_map('intval', $matches[1] ?? []));
    }

    /**
     * Заменить <img data-file-id="X"> на рендер <x-cms::image>.
     */
    protected function replaceImages(string $html, $files): string
    {
        // Используем DOMDocument для надёжного парсинга
        $dom = new DOMDocument();

        // Оборачиваем для корректной работы с UTF-8 и фрагментами
        $wrapped = '<?xml encoding="UTF-8"><div id="__tiptap_root__">' . $html . '</div>';

        // Подавляем ошибки для невалидного HTML (tiptap может генерировать незакрытые теги)
        @$dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);

        $xpath = new DOMXPath($dom);
        $images = $xpath->query('//img[@data-file-id]');

        $replacements = [];

        foreach ($images as $img) {
            $fileId = (int) $img->getAttribute('data-file-id');
            $size = $img->getAttribute('data-size') ?: null;
            $cssClass = $img->getAttribute('class') ?: '';
            $loading = $img->getAttribute('loading') ?: 'lazy';

            $file = $files[$fileId] ?? null;

            if ($file) {
                // Рендерим Blade-компонент <x-cms::image>
                $rendered = Blade::render(
                    '<x-cms::image :file="$file" :size="$size" :class="$class" :loading="$loading" />',
                    [
                        'file' => $file,
                        'size' => $size,
                        'class' => $cssClass,
                        'loading' => $loading,
                    ]
                );
                $replacements[] = ['node' => $img, 'html' => trim($rendered)];
            } else {
                // Файл удалён -- убираем img
                $replacements[] = ['node' => $img, 'html' => ''];
            }
        }

        // Применяем замены (в обратном порядке, чтобы не сломать индексы)
        foreach (array_reverse($replacements) as $r) {
            $node = $r['node'];
            if (empty($r['html'])) {
                // Удаляем узел
                $node->parentNode->removeChild($node);
            } else {
                // Заменяем узел на отрендеренный HTML.
                // appendXML() требует валидный XML, но Blade рендерит HTML5
                // (void-элементы <img>, <source> без закрывающих тегов),
                // поэтому парсим через loadHTML и импортируем узлы.
                $tmpDoc = new DOMDocument();
                @$tmpDoc->loadHTML(
                    '<?xml encoding="UTF-8"><div id="__tmp__">' . $r['html'] . '</div>',
                    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
                );
                $tmpRoot = $tmpDoc->getElementById('__tmp__');
                if ($tmpRoot && $tmpRoot->childNodes->length > 0) {
                    $fragment = $dom->createDocumentFragment();
                    foreach ($tmpRoot->childNodes as $child) {
                        $imported = $dom->importNode($child, true);
                        $fragment->appendChild($imported);
                    }
                    $node->parentNode->replaceChild($fragment, $node);
                } else {
                    // Fallback: если парсинг не удался, оставляем оригинальный узел
                }
            }
        }

        // Извлечь содержимое root-обёртки
        $root = $dom->getElementById('__tiptap_root__');
        if (!$root) {
            return $html; // fallback
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }
}
