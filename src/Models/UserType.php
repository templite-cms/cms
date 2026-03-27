<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserType extends Model
{
    protected $table = 'cms_user_types';

    protected $fillable = [
        'name',
        'slug',
        'guard',
        'module',
        'permissions',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'permissions' => 'json',
        'settings' => 'json',
        'is_active' => 'boolean',
    ];

    // --- Relationships ---

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'user_type_id');
    }

    public function fields(): HasMany
    {
        return $this->hasMany(UserField::class, 'user_type_id')->orderBy('order');
    }

    public function rootFields(): HasMany
    {
        return $this->hasMany(UserField::class, 'user_type_id')
            ->whereNull('parent_id')
            ->orderBy('order');
    }

    // --- Methods ---

    /**
     * Получить значение настройки по ключу.
     */
    public function getSetting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Разрешена ли регистрация для этого типа пользователей.
     */
    public function isRegistrationEnabled(): bool
    {
        return (bool) $this->getSetting('registration_enabled', false);
    }

    /**
     * Требуется ли подтверждение email для этого типа пользователей.
     */
    public function isEmailVerificationRequired(): bool
    {
        return (bool) $this->getSetting('email_verification_required', false);
    }
}
