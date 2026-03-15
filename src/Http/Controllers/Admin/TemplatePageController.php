<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Templite\Cms\Models\Component;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\Library;
use Templite\Cms\Models\PageTypeAttribute;
use Templite\Cms\Models\TemplatePage;

class TemplatePageController extends Controller
{
    /**
     * Список шаблонов.
     * Экран: Templates/Index
     */
    public function index(): Response
    {
        return Inertia::render('Templates/Index', [
            'templates' => TemplatePage::withCount('pages')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Редактирование шаблона.
     * Экран: Templates/Edit (IDE-подобный интерфейс)
     */
    public function edit(int $id): Response
    {
        $template = TemplatePage::with([
            'libraries',
            'tabs',
            'sections',
            'fields' => fn ($q) => $q->whereNull('parent_id')->orderBy('order'),
            'fields.children' => fn ($q) => $q->orderBy('order'),
            'fields.children.children' => fn ($q) => $q->orderBy('order'),
        ])->withCount('pages')->findOrFail($id);

        // Загружаем Blade/CSS/JS код шаблона из файлов
        $code = ['template_code' => '', 'style_code' => '', 'script_code' => ''];
        $codePath = storage_path('cms/templates/' . basename($template->slug));
        if (is_dir($codePath)) {
            if (file_exists($codePath . '/template.blade.php')) {
                $code['template_code'] = file_get_contents($codePath . '/template.blade.php');
            }
            if (file_exists($codePath . '/style.scss')) {
                $code['style_code'] = file_get_contents($codePath . '/style.scss');
            } elseif (file_exists($codePath . '/style.css')) {
                $code['style_code'] = file_get_contents($codePath . '/style.css');
            }
            if (file_exists($codePath . '/script.js')) {
                $code['script_code'] = file_get_contents($codePath . '/script.js');
            }
        }

        $templateData = array_merge($template->toArray(), $code);

        return Inertia::render('Templates/Edit', [
            'template' => $templateData,
            'allLibraries' => Library::where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'version']),
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
            'componentDefinitions' => Component::orderBy('name')
                ->get(['id', 'name', 'slug', 'params', 'description', 'source']),
        ]);
    }

    /**
     * Создание нового шаблона.
     * Экран: Templates/Edit (пустая форма)
     */
    public function create(): Response
    {
        return Inertia::render('Templates/Edit', [
            'template' => null,
        ]);
    }
}
