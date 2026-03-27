<?php

namespace Templite\Cms\Services;

use Templite\Cms\Contracts\UserGuardInterface;
use Templite\Cms\Models\UserField;
use Templite\Cms\Models\UserType;

class GuardRegistry
{
    /**
     * @var array<string, UserGuardInterface>
     */
    protected array $guards = [];

    /**
     * Зарегистрировать guard.
     *
     * @throws \RuntimeException если guard с таким именем уже зарегистрирован
     */
    public function register(UserGuardInterface $guard): void
    {
        $name = $guard->getGuard();

        if (isset($this->guards[$name])) {
            throw new \RuntimeException("Guard [{$name}] is already registered.");
        }

        $this->guards[$name] = $guard;
    }

    /**
     * Найти guard по имени.
     */
    public function find(string $guard): ?UserGuardInterface
    {
        return $this->guards[$guard] ?? null;
    }

    /**
     * Получить все зарегистрированные guard'ы.
     *
     * @return array<string, UserGuardInterface>
     */
    public function all(): array
    {
        return $this->guards;
    }

    /**
     * Проверить, зарегистрирован ли guard.
     */
    public function has(string $guard): bool
    {
        return isset($this->guards[$guard]);
    }

    /**
     * Получить опции для UI (select/dropdown).
     *
     * @return array<int, array{value: string, label: string, module: string, description: string}>
     */
    public function getOptions(): array
    {
        $options = [];

        foreach ($this->guards as $guard) {
            $options[] = [
                'value' => $guard->getGuard(),
                'label' => $guard->getLabel(),
                'module' => $guard->getModule(),
                'description' => $guard->getDescription(),
            ];
        }

        return $options;
    }

    /**
     * Получить разрешения для указанного guard'а.
     */
    public function getPermissionsForGuard(string $guard): array
    {
        $instance = $this->find($guard);

        return $instance ? $instance->getDefaultPermissions() : [];
    }

    /**
     * Настроить auth guards и providers для всех зарегистрированных guard'ов.
     */
    public function configureAuthGuards(): void
    {
        $config = app('config');

        foreach ($this->guards as $name => $guard) {
            $providerName = "cms_users_{$name}";

            $config->set("auth.guards.{$name}", [
                'driver' => 'session',
                'provider' => $providerName,
            ]);

            $config->set("auth.providers.{$providerName}", [
                'driver' => 'cms_users',
                'model' => \Templite\Cms\Models\User::class,
                'guard' => $name,
            ]);
        }
    }

    /**
     * Автосоздание UserType и дефолтных UserField для guard'ов,
     * у которых нет записи в БД.
     */
    public function ensureUserTypes(): void
    {
        foreach ($this->guards as $name => $guard) {
            if (UserType::where('guard', $name)->exists()) {
                continue;
            }

            $userType = UserType::create([
                'name' => $guard->getLabel(),
                'slug' => $name,
                'guard' => $name,
                'module' => $guard->getModule(),
                'permissions' => $guard->getDefaultPermissions(),
                'settings' => $guard->getDefaultSettings(),
                'is_active' => true,
            ]);

            $this->createFields($userType, $guard->getDefaultFields());
        }
    }

    /**
     * Создать поля для типа пользователя (с поддержкой вложенных children).
     */
    protected function createFields(UserType $userType, array $fields, ?int $parentId = null): void
    {
        foreach ($fields as $order => $fieldData) {
            $children = $fieldData['children'] ?? [];
            unset($fieldData['children']);

            $field = UserField::create(array_merge($fieldData, [
                'user_type_id' => $userType->id,
                'parent_id' => $parentId,
                'order' => $fieldData['order'] ?? $order,
            ]));

            if (!empty($children)) {
                $this->createFields($userType, $children, $field->id);
            }
        }
    }
}
