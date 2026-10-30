<?php

namespace Templite\Cms\Exceptions;

/**
 * Исключение при неудачной записи файла библиотеки на диск.
 *
 * Отдельный тип нужен, чтобы контроллер мог показать пользователю текст
 * сообщения, не рискуя выдать наружу что-то ещё: QueryException наследуется
 * от PDOException, а тот — от RuntimeException, поэтому перехват по
 * \RuntimeException заодно ловил бы ошибки запросов и печатал в ответ хост,
 * порт, имя базы и полный SQL в обход рендерера исключений Laravel.
 */
class LibraryFileWriteException extends \RuntimeException
{
}
