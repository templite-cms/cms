<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagerType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'json',
    ];

    // --- Relationships ---

    public function managers(): HasMany
    {
        return $this->hasMany(Manager::class, 'type_id');
    }

    // --- Methods ---

    /**
     * Проверить, есть ли разрешение у типа.
     */
    public function hasPermission(string $permission): bool
    {
        $permissions = $this->permissions ?? [];
        return in_array($permission, $permissions) || in_array('*', $permissions);
    }

    /**
     * Список всех доступных прав в системе (из всех модулей).
     */
    public static function getAvailablePermissions(): array
    {
        return app(\Templite\Cms\Services\ModuleRegistry::class)->getPermissionKeys();
    }

    /**
     * Права доступа, сгруппированные по модулям.
     */
    public static function getAvailablePermissionsGrouped(): array
    {
        return app(\Templite\Cms\Services\ModuleRegistry::class)->getPermissionsGrouped();
    }
}
