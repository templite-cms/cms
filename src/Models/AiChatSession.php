<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Диалог с AI-ассистентом.
 *
 * @property int $id
 * @property int $manager_id
 * @property string|null $title
 * @property string|null $model
 * @property string|null $image_model
 * @property int|null $key_id
 * @property string|null $summary
 * @property int|null $summarized_until_message_id
 * @property int|null $last_prompt_tokens
 * @property int|null $last_prompt_chars
 */
class AiChatSession extends Model
{
    protected $table = 'cms_ai_chat_sessions';

    protected $fillable = [
        'manager_id',
        'title',
        'model',
        'image_model',
        'key_id',
        'summary',
        'summarized_until_message_id',
        'last_prompt_tokens',
        'last_prompt_chars',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'session_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class, 'session_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    public function key(): BelongsTo
    {
        return $this->belongsTo(AiKey::class, 'key_id');
    }
}
