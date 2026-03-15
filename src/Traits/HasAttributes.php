<?php

namespace Templite\Cms\Traits;

use Illuminate\Database\Eloquent\Builder;
use Templite\Cms\Models\PageAttributeValue;

/**
 * Трейт для моделей с атрибутами (Page).
 * Предоставляет скоупы для фильтрации по атрибутам типа.
 */
trait HasAttributes
{
    /**
     * Фильтрация по значению атрибута.
     */
    public function scopeWhereAttribute(Builder $query, string $key, string $value): Builder
    {
        return $query->whereHas('attributeValues', function (Builder $q) use ($key, $value) {
            $q->whereHas('attribute', function (Builder $q2) use ($key) {
                $q2->where('key', $key);
            })->where('value', $value);
        });
    }

    /**
     * Фильтрация по нескольким значениям атрибута (IN).
     */
    public function scopeWhereAttributeIn(Builder $query, string $key, array $values): Builder
    {
        return $query->whereHas('attributeValues', function (Builder $q) use ($key, $values) {
            $q->whereHas('attribute', function (Builder $q2) use ($key) {
                $q2->where('key', $key);
            })->whereIn('value', $values);
        });
    }

    /**
     * Получить значение CMS-атрибута по ключу.
     */
    public function getCmsAttributeValue(string $key): ?string
    {
        $attrValue = $this->attributeValues()
            ->whereHas('attribute', fn(Builder $q) => $q->where('key', $key))
            ->first();

        return $attrValue?->value;
    }
}
