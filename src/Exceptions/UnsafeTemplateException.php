<?php

namespace Templite\Cms\Exceptions;

/**
 * Исключение при обнаружении небезопасных конструкций в Blade-шаблоне.
 *
 * Выбрасывается при рендере шаблона, содержащего запрещённые директивы,
 * функции или конструкции, которые могут привести к SSTI.
 */
class UnsafeTemplateException extends \RuntimeException
{
    /**
     * @param string $message Сообщение об ошибке
     * @param array $violations Список обнаруженных нарушений
     * @param int $code Код ошибки
     * @param \Throwable|null $previous Предыдущее исключение
     */
    public function __construct(
        string $message = 'Шаблон содержит небезопасные конструкции',
        protected array $violations = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Получить список нарушений безопасности.
     *
     * @return array
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
