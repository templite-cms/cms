<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagerLog extends Model
{
    protected $fillable = [
        'manager_id',
        'action',
        'entity_type',
        'entity_id',
        'data',
        'ip',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    // --- Relationships ---

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    // --- Scopes ---

    public function scopeByEntity($query, string $entityType, ?int $entityId = null)
    {
        $query->where('entity_type', $entityType);
        if ($entityId !== null) {
            $query->where('entity_id', $entityId);
        }
        return $query;
    }

    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeByManager($query, int $managerId)
    {
        return $query->where('manager_id', $managerId);
    }
}
