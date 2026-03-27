<?php

namespace Templite\Cms\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Templite\Cms\Models\UserType;

/**
 * Кастомный auth provider для пользователей сайта.
 *
 * Фильтрует пользователей по guard -> user_type_id, гарантируя что
 * авторизация через guard "author" найдёт только пользователей
 * с соответствующим UserType.
 */
class ScopedUserProvider extends EloquentUserProvider
{
    /**
     * Имя guard'а, по которому фильтруются пользователи.
     */
    protected ?string $guardName = null;

    /**
     * Кэш user_type_id для текущего guard'а (в рамках запроса).
     */
    protected int|false|null $cachedUserTypeId = null;

    /**
     * Установить имя guard'а.
     */
    public function setGuardName(string $guard): void
    {
        $this->guardName = $guard;
        $this->cachedUserTypeId = null; // сброс кэша при смене guard'а
    }

    /**
     * Получить user_type_id для текущего guard'а.
     *
     * Возвращает int если тип найден, false если не найден (кэшируется).
     */
    protected function getUserTypeId(): ?int
    {
        if ($this->cachedUserTypeId === null) {
            if (!$this->guardName) {
                $this->cachedUserTypeId = false;
                return null;
            }

            $id = UserType::where('guard', $this->guardName)
                ->where('is_active', true)
                ->value('id');

            $this->cachedUserTypeId = $id ?? false;
        }

        return $this->cachedUserTypeId === false ? null : $this->cachedUserTypeId;
    }

    /**
     * Применить scope guard'а к запросу: user_type_id + is_active.
     */
    protected function applyScopeToQuery(Builder $query): Builder
    {
        $userTypeId = $this->getUserTypeId();

        if ($userTypeId !== null) {
            $query->where('user_type_id', $userTypeId);
        }

        $query->where('is_active', true);

        return $query;
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        $model = $this->createModel();

        $query = $this->newModelQuery($model)
            ->where($model->getAuthIdentifierName(), $identifier);

        $this->applyScopeToQuery($query);

        return $query->first();
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        $model = $this->createModel();

        $retrievedModel = $this->newModelQuery($model)
            ->where($model->getAuthIdentifierName(), $identifier);

        $this->applyScopeToQuery($retrievedModel);

        $retrievedModel = $retrievedModel->first();

        if (!$retrievedModel) {
            return null;
        }

        $rememberToken = $retrievedModel->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token)
            ? $retrievedModel
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $credentials = array_filter(
            $credentials,
            fn ($key) => !str_contains($key, 'password'),
            ARRAY_FILTER_USE_KEY
        );

        if (empty($credentials)) {
            return null;
        }

        $query = $this->newModelQuery();

        foreach ($credentials as $key => $value) {
            if (is_array($value)) {
                $query->whereIn($key, $value);
            } elseif ($value instanceof \Closure) {
                $value($query);
            } else {
                $query->where($key, $value);
            }
        }

        $this->applyScopeToQuery($query);

        return $query->first();
    }
}
