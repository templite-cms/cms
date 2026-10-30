<?php

namespace Templite\Cms\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class Manager extends Authenticatable
{
    use HasApiTokens;
    protected $fillable = [
        'login',
        'email',
        'name',
        'password',
        'type_id',
        'settings',
        'personal_permissions',
        'use_personal_permissions',
        'avatar_id',
        'is_active',
        'active_ai_key_id',
    ];

    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'settings' => 'json',
        'personal_permissions' => 'json',
        'use_personal_permissions' => 'boolean',
        'is_active' => 'boolean',
        'two_factor_confirmed_at' => 'datetime',
    ];

    protected $appends = ['avatar_url'];

    // --- Accessors ---

    /**
     * URL аватара: загруженный файл или дефолтный аватар по ID менеджера.
     */
    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar_id) {
            if ($this->relationLoaded('avatar') && $this->avatar) {
                return $this->avatar->disk === 'local'
                    ? '/api/cms/media/serve/' . $this->avatar->id
                    : $this->avatar->url;
            }

            return '/api/cms/media/serve/' . $this->avatar_id;
        }

        $number = $this->id ? (($this->id - 1) % 30) + 1 : 1;

        return '/api/cms/avatars/' . str_pad($number, 2, '0', STR_PAD_LEFT);
    }

    // --- Relationships ---

    public function type(): BelongsTo
    {
        return $this->belongsTo(ManagerType::class, 'type_id');
    }

    /**
     * Алиас для type() — используется в middleware и ресурсах.
     */
    public function managerType(): BelongsTo
    {
        return $this->belongsTo(ManagerType::class, 'type_id');
    }

    public function avatar(): BelongsTo
    {
        return $this->belongsTo(File::class, 'avatar_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ManagerSession::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ManagerLog::class);
    }

    public function trustedDevices(): HasMany
    {
        return $this->hasMany(ManagerTrustedDevice::class);
    }

    /** Ключи, которыми менеджер владеет. */
    public function aiKeys(): HasMany
    {
        return $this->hasMany(AiKey::class, 'owner_manager_id');
    }

    /** Чужие ключи, к которым менеджеру выдали доступ. */
    public function sharedAiKeys(): BelongsToMany
    {
        return $this->belongsToMany(AiKey::class, 'cms_ai_key_access', 'manager_id', 'key_id')
            ->withTimestamps();
    }

    public function activeAiKey(): BelongsTo
    {
        return $this->belongsTo(AiKey::class, 'active_ai_key_id');
    }

    // --- Methods ---

    /**
     * Проверить, включена ли 2FA у менеджера.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return !is_null($this->two_factor_confirmed_at);
    }

    /**
     * Проверить, есть ли разрешение у менеджера.
     * Если включены персональные права -- проверяет их, иначе права типа.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->getPermissions();

        // Как Gate::before в AuthManager: '*' — всё, 'pages.*' — вся группа pages.
        // Без этого инструменты ассистента и MCP отказывали тому, кого пускают маршруты.
        $group = explode('.', $permission)[0];

        return in_array('*', $permissions, true)
            || in_array($group.'.*', $permissions, true)
            || in_array($permission, $permissions, true);
    }

    /**
     * Получить все разрешения менеджера.
     */
    public function getPermissions(): array
    {
        if ($this->use_personal_permissions) {
            return $this->personal_permissions ?? [];
        }

        return $this->type?->permissions ?? [];
    }

    /**
     * Является ли менеджер администратором.
     */
    public function isAdmin(): bool
    {
        return $this->hasPermission('*');
    }

    /**
     * Проверить, что все указанные права не превышают права данного менеджера.
     * Администратор (*) может назначать любые права.
     * Обычный менеджер может назначить только те права, которые есть у него самого.
     *
     * @param array $permissions Массив прав для проверки
     * @return array Массив прав, которые превышают права данного менеджера
     */
    public function getExceedingPermissions(array $permissions): array
    {
        if ($this->isAdmin()) {
            return [];
        }

        $ownPermissions = $this->getPermissions();

        return array_values(array_filter($permissions, function (string $perm) use ($ownPermissions) {
            return !in_array($perm, $ownPermissions, true);
        }));
    }
}
