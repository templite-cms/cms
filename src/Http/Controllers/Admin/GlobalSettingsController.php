<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\GlobalFieldPage;
use Templite\Cms\Services\GlobalSettingsFormData;

class GlobalSettingsController extends Controller
{
    /**
     * Глобальные настройки (все вкладки, секции, поля, значения).
     * Экран: Settings/Index
     */
    public function index(GlobalSettingsFormData $formData): Response
    {
        // Ровно тот же набор данных отдаёт GET /api/cms/settings/form, которым
        // экран перечитывает себя после структурных правок ассистента: сборка
        // одна на два пути, чтобы рендер и обновление не разъезжались.
        ['pages' => $pages, 'values' => $values] = $formData->build();

        return CmsResponse::page('packages/templite/cms/resources/js/entries/settings-index.js', [
            'pages' => $pages,
            'values' => $values,
            'fieldTypes' => config('cms.field_types', [
                'text', 'textfield', 'number', 'img', 'file', 'editor', 'tiptap', 'html',
                'select', 'checkbox', 'radio', 'link', 'date', 'datetime',
                'array', 'color', 'page',
            ]),
        ], ['title' => 'Настройки']);
    }

    /**
     * Структура глобальных настроек (CRUD вкладок, секций, полей).
     * Экран: Settings/Structure
     */
    public function structure(): Response
    {
        $pages = GlobalFieldPage::with([
            'sections' => fn ($q) => $q->orderBy('order'),
            'sections.fields' => fn ($q) => $q->whereNull('parent_id')->orderBy('order'),
            'sections.fields.allChildren',
        ])->orderBy('order')->get();

        return CmsResponse::page('packages/templite/cms/resources/js/entries/settings-structure.js', [
            'pages' => $pages,
            'fieldTypes' => config('cms.field_types', [
                'text', 'textfield', 'number', 'img', 'file', 'editor', 'tiptap', 'html',
                'select', 'checkbox', 'radio', 'link', 'date', 'datetime',
                'array', 'color', 'page',
            ]),
        ], ['title' => 'Структура настроек']);
    }
}
