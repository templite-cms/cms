<?php

namespace Templite\Cms\Services\Ai\Settings;

/**
 * Где ключ глобального поля встречается в коде сайта.
 *
 * Нужен отчёту перед удалением: поле уходит вместе со значениями, а вёрстка,
 * которая на него ссылалась, останется — и покажет пустоту вместо телефона.
 * Ищем по тем же каталогам, что и реестры блоков, шаблонов, компонентов и
 * actions: app-источник, storage-источник, файлы пакета.
 */
class SettingsUsageScanner
{
    /** @return array<int,string> относительные пути файлов, где ключ упоминается */
    public function find(string $key): array
    {
        $roots = array_filter([
            app_path('Blocks'),
            app_path('Actions'),
            app_path('View/Components/Cms'),
            storage_path('cms/blocks'),
            storage_path('cms/templates'),
            storage_path('cms/components'),
            storage_path('cms/actions'),
            // Этот файл лежит в src/Services/Ai/Settings — на 2 уровня глубже,
            // чем ComponentRegistry (src/Services), поэтому и подниматься
            // нужно на 2 уровня дальше: dirname(__DIR__, 4), а не 3, иначе
            // путь упирается в несуществующий src/resources и корень пакета
            // (packages/templite/cms/resources/views/components) не сканируется.
            dirname(__DIR__, 4).'/resources/views/components',
        ], 'is_dir');

        // $global['key'], $global["key"], $context->global['key'] — во всех
        // трёх случаях между словом global и ключом не больше пары символов.
        $pattern = '~global\b.{0,8}[\'"]'.preg_quote($key, '~').'[\'"]~u';
        $found = [];

        foreach ($roots as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }

                $contents = @file_get_contents($file->getPathname());

                if ($contents !== false && preg_match($pattern, $contents)) {
                    $found[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        return $found;
    }
}
