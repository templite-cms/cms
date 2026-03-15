<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagerSession extends Model
{
    protected $fillable = [
        'manager_id',
        'token',
        'user_agent',
        'ip',
        'last_active',
        'expires_at',
    ];

    protected $casts = [
        'last_active' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // --- Relationships ---

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Manager::class);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }
}
