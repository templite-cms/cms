<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Component;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Models\Library;
use Templite\Cms\Models\PageTypeAttribute;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Services\ComponentRegistry;

class TemplatePageController extends Controller
{
    public function __construct(protected ComponentRegistry $componentRegistry) {}
    /**
     * Список шаблонов.
     * Экран: Templates/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/templates-index.js', [
            'templates' => TemplatePage::withCount('pages')
                ->orderBy('name')
                ->get(),
        ], ['title' => 'Шаблоны']);
    }

    /**
     * Редактирование шаблона.
     * Экран: Templates/Edit (IDE-подобный интерфейс)
     */
    public function edit(int $id)
    {
        $template = TemplatePage::with([
            'libraries',
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

        return CmsResponse::page('packages/templite/cms/resources/js/entries/templates-edit.js', [
            'template' => $templateData,
            // version — аксессор поверх версий файлов, колонки с таким именем
            // у библиотеки нет; files грузим здесь, иначе он сходит в базу
            // за каждой библиотекой списка
            'allLibraries' => Library::where('active', true)
                ->with('files:id,library_id,version,sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'slug'])
                ->append('version')
                ->makeHidden('files'),
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
            'bladeComponentReference' => $this->componentRegistry->getBladeComponentReference(),
        ], ['title' => $template->name]);
    }

    /**
     * Создание нового шаблона.
     * Экран: Templates/Edit (пустая форма)
     */
    public function create()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/templates-edit.js', [
            'template' => null,
        ], ['title' => 'Новый шаблон']);
    }
}
