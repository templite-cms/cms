<?php

namespace Templite\Cms\Contracts;

use Illuminate\Support\Collection;

/**
 * Единый интерфейс для реестров сущностей (BlockRegistry, ActionRegistry, ComponentRegistry).
 * Реализует поиск по трём источникам с приоритетом:
 * 1. app/ (код разработчика) -- ВЫСШИЙ приоритет
 * 2. storage/cms/ (из админки) -- СРЕДНИЙ приоритет
 * 3. vendor/templite/ (из пакета) -- НИЗШИЙ приоритет
 */
interface RegistryInterface
{
    /**
     * Получить сущность по slug.
     * Поиск идёт по приоритету: app/ -> storage/cms/ -> vendor/templite/.
     * Возвращает первый найденный результат.
     *
     * @param string $slug
     * @return mixed|null Найденная сущность или null
     */
    public function find(string $slug): mixed;

    /**
     * Получить все сущности из всех источников.
     * При совпадении slug -- побеждает источник с более высоким приоритетом.
     * Каждая сущность имеет поле 'source': 'app', 'storage', 'vendor'.
     *
     * @return Collection
     */
    public function all(): Collection;

    /**
     * Проверить существование сущности по slug.
     *
     * @param string $slug
     * @return bool
     */
    public function exists(string $slug): bool;

    /**
     * Зарегистрировать сущность из указанного источника.
     *
     * @param string $slug
     * @param mixed $entity
     * @param string $source Один из: 'app', 'storage', 'vendor'
     * @return void
     */
    public function register(string $slug, mixed $entity, string $source): void;

    /**
     * Получить все сущности из конкретного источника.
     *
     * @param string $source Один из: 'app', 'storage', 'vendor'
     * @return Collection
     */
    public function fromSource(string $source): Collection;
}
