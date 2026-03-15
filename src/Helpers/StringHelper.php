<?php

namespace Templite\Cms\Helpers;

class StringHelper
{
    /**
     * Экранирование спецсимволов LIKE-оператора SQL.
     *
     * Предотвращает LIKE injection: пользовательский ввод с символами
     * %, _ или \ не будет интерпретирован как wildcard.
     */
    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
