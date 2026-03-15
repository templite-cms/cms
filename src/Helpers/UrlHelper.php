<?php

namespace Templite\Cms\Helpers;

/**
 * Утилиты для безопасной работы с URL.
 */
class UrlHelper
{
    /**
     * Проверяет, что URL является внутренним (относительным или принадлежит текущему домену).
     *
     * Защищает от Open Redirect: отклоняет URL, ведущие на внешние домены,
     * а также URL со схемами javascript:, data: и т.д.
     *
     * @param string $url URL для проверки
     * @return bool true если URL внутренний и безопасный
     */
    public static function isInternalUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        // Блокируем опасные схемы (javascript:, data:, vbscript: и т.д.)
        if (preg_match('/^\s*[a-zA-Z][a-zA-Z0-9+.\-]*:/i', $url)) {
            // Разрешаем только http и https
            if (!preg_match('/^\s*https?:\/\//i', $url)) {
                return false;
            }
        }

        // Блокируем protocol-relative URL (//evil.com)
        if (preg_match('/^\s*\/\//i', $url)) {
            return false;
        }

        // Относительные URL (начинаются с / но не с //) — безопасны
        if (preg_match('/^\/[^\/]/', $url) || $url === '/') {
            return true;
        }

        // Абсолютные URL — проверяем, что хост совпадает с текущим
        $parsed = parse_url($url);

        if ($parsed === false || !isset($parsed['host'])) {
            // Относительные пути без ведущего / (например "page/sub")
            // Считаем безопасными, если нет схемы
            if (!isset($parsed['scheme'])) {
                return true;
            }
            return false;
        }

        $currentHost = request()->getHost();

        return strcasecmp($parsed['host'], $currentHost) === 0;
    }
}
