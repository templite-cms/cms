<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Событие хода: то же, что раньше уходило в браузер по SSE напрямую.
 *
 * @property int $id
 * @property int $run_id
 * @property string $type
 * @property array|null $payload
 */
class AiRunEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'cms_ai_run_events';

    protected $fillable = ['run_id', 'type', 'payload', 'created_at'];

    protected $casts = ['payload' => 'array'];
}
