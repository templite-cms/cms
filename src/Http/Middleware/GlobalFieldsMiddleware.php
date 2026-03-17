<?php

namespace Templite\Cms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\GlobalFieldValue;
use Templite\Cms\Models\GlobalFieldValueTranslation;
use Templite\Cms\Services\CacheManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для загрузки глобальных полей.
 *
 * Загружает все глобальные поля (настройки сайта) и делает
 * их доступными через app('global_fields').
 * Используется на публичных маршрутах для рендеринга страниц.
 */
class GlobalFieldsMiddleware
{
    public function __construct(protected CacheManager $cacheManager) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $currentLang = app()->bound('current_language') ? app('current_language') : null;
        $cacheKey = 'global_fields' . ($currentLang ? '_' . $currentLang : '');

        // Пробуем взять из кэша, если нет — загружаем из БД и кэшируем
        $globalFields = $this->cacheManager->getGlobalFieldsByKey($cacheKey);

        if ($globalFields === null) {
            $globalFields = $this->loadGlobalFields();
            $this->cacheManager->putGlobalFieldsByKey($cacheKey, $globalFields);
        }

        // Регистрируем в контейнере (всегда массив, даже пустой)
        app()->instance('global_fields', $globalFields);

        // Делаем доступными через View
        view()->share('global', $globalFields);

        return $next($request);
    }

    /**
     * Загрузка всех глобальных полей из БД.
     */
    protected function loadGlobalFields(): array
    {
        $fields = GlobalField::with(['values', 'children.values'])
            ->whereNull('parent_id')
            ->get();

        $result = [];

        foreach ($fields as $field) {
            $key = $field->key;

            if ($field->isRepeater()) {
                // Повторитель: массив объектов
                $result[$key] = $this->resolveRepeaterValues($field);
            } else {
                $result[$key] = \Templite\Cms\Support\FieldValueCaster::cast($field->getValue(), $field->type);
            }
        }

        // Merge translations for non-default language
        $currentLang = app()->bound('current_language') ? app('current_language') : null;
        $isDefaultLang = app()->bound('is_default_language') ? app('is_default_language') : true;

        if ($currentLang && !$isDefaultLang) {
            $result = $this->mergeTranslations($result, $fields, $currentLang);
        }

        return $result;
    }

    /**
     * Резолв значений повторителя.
     */
    protected function resolveRepeaterValues(GlobalField $field): array
    {
        $parentValues = GlobalFieldValue::where('global_field_id', $field->id)
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get();

        $items = [];

        foreach ($parentValues as $parentValue) {
            $item = [];

            // Дочерние значения этого элемента повторителя
            $childValues = GlobalFieldValue::where('parent_id', $parentValue->id)->get();

            foreach ($childValues as $childValue) {
                $childField = $field->children->firstWhere('id', $childValue->global_field_id);
                if ($childField) {
                    $item[$childField->key] = \Templite\Cms\Support\FieldValueCaster::cast($childValue->value, $childField->type);
                }
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * Наложить переводы на значения глобальных полей.
     *
     * Для скалярных полей: подставляет переведённое значение вместо дефолтного.
     * Для повторителей: подставляет переведённые значения дочерних полей по ID.
     */
    protected function mergeTranslations(array $result, $fields, string $lang): array
    {
        // Собираем все ID значений для batch-загрузки переводов
        $allValueIds = [];
        foreach ($fields as $field) {
            foreach ($field->values as $value) {
                $allValueIds[] = $value->id;
            }
            // Дочерние значения для repeater-полей
            if ($field->isRepeater()) {
                $parentValues = $field->values->whereNull('parent_id');
                foreach ($parentValues as $parentValue) {
                    $childValues = GlobalFieldValue::where('parent_id', $parentValue->id)->get();
                    foreach ($childValues as $child) {
                        $allValueIds[] = $child->id;
                    }
                }
            }
        }

        if (empty($allValueIds)) {
            return $result;
        }

        $translations = GlobalFieldValueTranslation::whereIn('global_field_value_id', $allValueIds)
            ->where('lang', $lang)
            ->get()
            ->keyBy('global_field_value_id');

        if ($translations->isEmpty()) {
            return $result;
        }

        foreach ($fields as $field) {
            $key = $field->key;

            if ($field->isRepeater()) {
                // Повторитель: наложить переводы дочерних значений
                $parentValues = GlobalFieldValue::where('global_field_id', $field->id)
                    ->whereNull('parent_id')
                    ->orderBy('order')
                    ->get();

                foreach ($parentValues as $itemIdx => $parentValue) {
                    $childValues = GlobalFieldValue::where('parent_id', $parentValue->id)->get();

                    foreach ($childValues as $childValue) {
                        $childField = $field->children->firstWhere('id', $childValue->global_field_id);
                        if ($childField) {
                            $t = $translations->get($childValue->id);
                            if ($t && $t->value !== null) {
                                if (isset($result[$key][$itemIdx])) {
                                    $result[$key][$itemIdx][$childField->key] = \Templite\Cms\Support\FieldValueCaster::cast($t->value, $childField->type);
                                }
                            }
                        }
                    }
                }
            } else {
                // Скалярное поле: подставить перевод
                $value = $field->values->first();
                if ($value) {
                    $t = $translations->get($value->id);
                    if ($t && $t->value !== null) {
                        $result[$key] = \Templite\Cms\Support\FieldValueCaster::cast($t->value, $field->type);
                    }
                }
            }
        }

        return $result;
    }
}
