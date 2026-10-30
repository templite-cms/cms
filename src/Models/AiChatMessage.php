<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Сообщение диалога. В meta лежит то, что нужно для отрисовки:
 * чипы вызванных инструментов, ссылки navigate, request_id.
 *
 * @property int $id
 * @property int $session_id
 * @property string $role
 * @property string|null $content
 * @property array|null $meta
 */
class AiChatMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cms_ai_chat_messages';

    protected $fillable = ['session_id', 'role', 'content', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'session_id');
    }
}
