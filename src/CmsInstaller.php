<?php

namespace Templite\Cms;

use Illuminate\Support\Facades\File;

/**
 * Логика команды cms:install.
 * Создаёт необходимые директории, публикует конфиги.
 */
class CmsInstaller
{
    /**
     * Выполнить установку CMS.
     */
    public function install(): array
    {
        $results = [];

        // Создать директории для кастомных блоков, actions, компонентов
        $directories = [
            app_path('Blocks'),
            app_path('Actions'),
            app_path('View/Components/Cms'),
            storage_path('cms/blocks'),
            storage_path('cms/actions'),
            storage_path('cms/components'),
        ];

        foreach ($directories as $dir) {
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
                $results[] = "Создана директория: {$dir}";
            }
        }

        return $results;
    }
}
