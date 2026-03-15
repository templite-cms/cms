<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportImportLog extends Model
{
    protected $fillable = [
        'type',
        'manager_id',
        'filename',
        'entity_summary',
        'conflicts',
        'status',
        'error_message',
        'file_size',
    ];

    protected $casts = [
        'entity_summary' => 'json',
        'conflicts' => 'json',
    ];

    // --- Relationships ---

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    // --- Scopes ---

    public function scopeExports($query)
    {
        return $query->where('type', 'export');
    }

    public function scopeImports($query)
    {
        return $query->where('type', 'import');
    }
}
