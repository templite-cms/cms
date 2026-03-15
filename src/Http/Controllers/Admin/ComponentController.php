<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\Component;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\PageTypeAttribute;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\ComponentRegistry;

class ComponentController extends Controller
{
    public function __construct(protected ComponentRegistry $componentRegistry) {}

    /**
     * Список компонентов.
     * Экран: Components/Index
     */
    public function index(): Response
    {
        return Inertia::render('Components/Index', [
            'components' => Component::orderBy('name')->get(),
            'registryComponents' => $this->componentRegistry->all(),
        ]);
    }

    /**
     * Редактирование компонента.
     * Экран: Components/Edit (CodePen IDE)
     */
    public function edit(int $id): Response
    {
        $component = Component::findOrFail($id);

        // Загружаем код компонента из файлов
        $code = ['template_code' => '', 'style_code' => '', 'script_code' => ''];
        $path = storage_path('cms/components/' . basename($component->slug));
        if (is_dir($path)) {
            if (file_exists($path . '/index.blade.php')) {
                $code['template_code'] = file_get_contents($path . '/index.blade.php');
            }
            if (file_exists($path . '/style.scss')) {
                $code['style_code'] = file_get_contents($path . '/style.scss');
            } elseif (file_exists($path . '/style.css')) {
                $code['style_code'] = file_get_contents($path . '/style.css');
            }
            if (file_exists($path . '/script.js')) {
                $code['script_code'] = file_get_contents($path . '/script.js');
            }
        }

        // Мержим код в данные компонента
        $componentData = array_merge($component->toArray(), $code);

        return Inertia::render('Components/Edit', [
            'component' => $componentData,
            'templates' => TemplatePage::orderBy('name')->get(['id', 'name', 'slug']),
            'globalFieldDefinitions' => GlobalField::orderBy('order')
                ->get(['id', 'key', 'name', 'type', 'parent_id']),
            'pageAttributeDefinitions' => PageTypeAttribute::with('pageType:id,name')
                ->orderBy('order')
                ->get(['id', 'page_type_id', 'key', 'name', 'type'])
                ->map(fn ($attr) => [
                    'key' => $attr->key,
                    'name' => $attr->name,
                    'type' => $attr->type,
                    'page_type_name' => $attr->pageType->name ?? 'Без типа',
                ]),
        ]);
    }

    /**
     * Создание нового компонента.
     * Экран: Components/Edit (пустая форма)
     */
    public function create(): Response
    {
        return Inertia::render('Components/Edit', [
            'component' => null,
            'templates' => TemplatePage::orderBy('name')->get(['id', 'name', 'slug']),
            'globalFieldDefinitions' => GlobalField::orderBy('order')
                ->get(['id', 'key', 'name', 'type', 'parent_id']),
            'pageAttributeDefinitions' => PageTypeAttribute::with('pageType:id,name')
                ->orderBy('order')
                ->get(['id', 'page_type_id', 'key', 'name', 'type'])
                ->map(fn ($attr) => [
                    'key' => $attr->key,
                    'name' => $attr->name,
                    'type' => $attr->type,
                    'page_type_name' => $attr->pageType->name ?? 'Без типа',
                ]),
        ]);
    }
}
