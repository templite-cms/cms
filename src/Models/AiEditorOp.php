<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Правка, предложенная агентом в редактор. Применяет её сам редактор —
 * живым событием или при следующем открытии.
 *
 * @property int $id
 * @property int|null $run_id
 * @property int $manager_id
 * @property string $target_type
 * @property string $target_id
 * @property array $op
 * @property string $status
 */
class AiEditorOp extends Model
{
    public const PENDING = 'pending';

    public const APPLIED = 'applied';

    public const DISCARDED = 'discarded';

    protected $table = 'cms_ai_editor_ops';

    protected $fillable = ['run_id', 'manager_id', 'target_type', 'target_id', 'op', 'status', 'applied_at'];

    protected $casts = [
        'op' => 'array',
        'applied_at' => 'datetime',
    ];
}
