<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ход агента: ответ на одно сообщение пользователя. Выполняется в фоне,
 * события пишутся в журнал cms_ai_run_events — по нему браузер догоняет
 * ход после перезагрузки.
 *
 * @property int $id
 * @property int $session_id
 * @property int $manager_id
 * @property int $message_id
 * @property int|null $assistant_message_id
 * @property string $status
 * @property string|null $driver
 * @property array|null $context
 * @property \Illuminate\Support\Carbon|null $cancel_requested_at
 * @property \Illuminate\Support\Carbon|null $heartbeat_at
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property array|null $error
 * @property array|null $usage
 */
class AiRun extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const INTERRUPTED = 'interrupted';

    /** Ход ещё не закончен. */
    public const ACTIVE = [self::QUEUED, self::RUNNING];

    protected $table = 'cms_ai_runs';

    protected $fillable = [
        'session_id',
        'manager_id',
        'message_id',
        'assistant_message_id',
        'status',
        'driver',
        'context',
        'cancel_requested_at',
        'heartbeat_at',
        'started_at',
        'finished_at',
        'error',
        'usage',
    ];

    protected $casts = [
        'context' => 'array',
        'error' => 'array',
        'usage' => 'array',
        'cancel_requested_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'session_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AiRunEvent::class, 'run_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /** Краткая карточка для панели чата. */
    public function brief(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'status' => $this->status,
            'message_id' => $this->message_id,
            'assistant_message_id' => $this->assistant_message_id,
        ];
    }
}
