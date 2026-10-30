<?php

namespace Templite\Cms\Services\Ai;

use Templite\Cms\Models\AiChatSession;

/**
 * Диалог и признак того, что он только что переехал на другой ключ.
 *
 * Признак нужен контроллеру: модели, присланные панелью, относятся к прежнему
 * ключу, и применять их к новому нельзя — у другого провайдера свои имена моделей.
 */
class ResolvedSession
{
    public function __construct(
        public readonly AiChatSession $session,
        public readonly bool $keyChanged,
    ) {
    }
}
