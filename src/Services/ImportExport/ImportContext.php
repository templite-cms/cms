<?php

namespace Templite\Cms\Services\ImportExport;

use Illuminate\Database\Eloquent\Model;

/**
 * Контекст импорта -- хранит маппинг identifier -> model.
 *
 * При импорте сущностей из JSON нужно разрешать связи:
 * экспортированные данные ссылаются на зависимости по slug/key,
 * а в текущей БД у них другие ID.
 *
 * ImportContext аккумулирует уже импортированные модели
 * и позволяет последующим сущностям получить нужные ID.
 *
 * Также хранит решения пользователя по конфликтам
 * (skip, overwrite, rename) для каждой сущности.
 */
class ImportContext
{
    /**
     * Маппинг импортированных моделей.
     *
     * @var array<string, array<string, Model>> type => [identifier => model]
     */
    protected array $map = [];

    /**
     * Решения по конфликтам: что делать если сущность уже существует.
     *
     * @var array<string, string> "type:identifier" => action (skip|overwrite|rename)
     */
    protected array $conflictActions = [];

    /**
     * Зарегистрировать импортированную модель.
     *
     * @param string $type Тип сущности (например, 'block', 'page_type')
     * @param string $identifier Уникальный идентификатор (slug/key)
     * @param Model $model Созданная или найденная модель
     */
    public function register(string $type, string $identifier, Model $model): void
    {
        $this->map[$type][$identifier] = $model;
    }

    /**
     * Получить модель по типу и идентификатору.
     *
     * @param string $type Тип сущности
     * @param string $identifier Уникальный идентификатор
     * @return Model|null Модель или null, если ещё не импортирована
     */
    public function resolve(string $type, string $identifier): ?Model
    {
        return $this->map[$type][$identifier] ?? null;
    }

    /**
     * Получить ID модели по типу и идентификатору.
     *
     * Удобный shortcut для использования в fromImportArray(),
     * когда нужно заполнить foreign key.
     *
     * @param string $type Тип сущности
     * @param string $identifier Уникальный идентификатор
     * @return int|null ID модели или null
     */
    public function resolveId(string $type, string $identifier): ?int
    {
        return $this->resolve($type, $identifier)?->id;
    }

    /**
     * Установить решения по конфликтам.
     *
     * Формат: ["block:hero-banner" => "overwrite", "page_type:blog" => "skip"]
     *
     * @param array<string, string> $actions
     */
    public function setConflictActions(array $actions): void
    {
        $this->conflictActions = $actions;
    }

    /**
     * Получить решение по конфликту для конкретной сущности.
     *
     * По умолчанию возвращает 'skip' -- не перезаписывать существующее.
     *
     * @param string $type Тип сущности
     * @param string $identifier Уникальный идентификатор
     * @return string Действие: 'skip', 'overwrite' или 'rename'
     */
    public function getConflictAction(string $type, string $identifier): string
    {
        return $this->conflictActions["{$type}:{$identifier}"] ?? 'skip';
    }

    /**
     * Получить все зарегистрированные модели определённого типа.
     *
     * @param string $type Тип сущности
     * @return array<string, Model> identifier => model
     */
    public function all(string $type): array
    {
        return $this->map[$type] ?? [];
    }
}
