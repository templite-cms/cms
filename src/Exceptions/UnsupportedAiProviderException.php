<?php

namespace Templite\Cms\Exceptions;

use RuntimeException;

/**
 * Ключ заведён на провайдера, клиента для которого в CMS пока нет.
 * Колонка provider появилась раньше вторых реализаций — намеренно.
 */
class UnsupportedAiProviderException extends RuntimeException
{
    public function __construct(string $provider)
    {
        parent::__construct("Провайдер «{$provider}» пока не поддерживается.");
    }
}
