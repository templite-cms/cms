<?php

namespace Templite\Cms\Services\Ai;

/**
 * Тексты для AI (инструкции, сценарии) по принципу трёх источников:
 * файл приложения resources/cms/ai/… заменяет файл пакета resources/ai/…
 * целиком. Так сайт подстраивает правила под себя, а обновления пакета
 * не затирают его правки.
 */
class AiTextRepository
{
    public function get(string $path): string
    {
        $file = $this->locate($path);

        if ($file === null) {
            throw new \RuntimeException("Текст AI не найден: {$path}");
        }

        return (string) file_get_contents($file);
    }

    public function exists(string $path): bool
    {
        return $this->locate($path) !== null;
    }

    private function locate(string $path): ?string
    {
        // Имя приходит и из URL (prompts/{name}) — выход из каталога запрещён.
        if (str_contains($path, '..')) {
            return null;
        }

        foreach ([resource_path('cms/ai/'.$path), __DIR__.'/../../../resources/ai/'.$path] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return null;
    }
}
