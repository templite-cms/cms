<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;

use Templite\Cms\Models\Action;
use Templite\Cms\Models\GlobalField;
use Templite\Cms\Services\ActionRegistry;

class ActionController extends Controller
{
    public function __construct(protected ActionRegistry $actionRegistry) {}

    /**
     * Список actions.
     * Экран: Actions/Index
     */
    public function index()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/actions-index.js', [
            'actions' => Action::with('screenshot')->withCount('blockActions')->orderBy('name')->get()->map(fn($a) => array_merge($a->toArray(), [
                'screenshot_url' => $a->screenshot?->url(),
            ])),
            'registryActions' => $this->actionRegistry->all(),
        ], ['title' => 'Действия']);
    }

    /**
     * Редактирование action.
     * Экран: Actions/Edit
     */
    public function edit(int $id)
    {
        $action = Action::with(['blockActions.block', 'screenshot'])->findOrFail($id);

        $code = $this->resolveActionCode($action);
        $action->setAttribute('code', $code);

        $props = [
            'action' => $action,
            'globalFieldDefinitions' => GlobalField::select('key', 'type', 'name')->whereNull('parent_id')->get(),
        ];

        // Для нового действия без кода — подставить шаблон
        if ($code === '') {
            $props['defaultCode'] = $this->getDefaultActionCode();
        }

        return CmsResponse::page('packages/templite/cms/resources/js/entries/actions-edit.js', $props, ['title' => $action->name]);
    }

    /**
     * Получить исходный код action из трёх источников (принцип трёх источников):
     * 1. storage/cms/actions/{slug}.php (из админки)
     * 2. app/Actions/{ClassName}.php (код разработчика)
     * 3. vendor class via class_name (из пакета)
     */
    protected function resolveActionCode(Action $action): string
    {
        // 1. storage/cms/actions/{slug}.php — высший приоритет для редактируемых
        $storagePath = storage_path('cms/actions/' . basename($action->slug) . '.php');
        if (file_exists($storagePath)) {
            return file_get_contents($storagePath);
        }

        // 2-3. Если есть class_name — читаем исходный файл класса через Reflection
        if ($action->class_name && class_exists($action->class_name)) {
            try {
                $reflection = new \ReflectionClass($action->class_name);
                $filePath = $reflection->getFileName();
                if ($filePath && file_exists($filePath)) {
                    return file_get_contents($filePath);
                }
            } catch (\ReflectionException $e) {
                // класс не найден — вернём пустую строку
            }
        }

        return '';
    }

    /**
     * Создание нового action.
     * Экран: Actions/Edit (пустая форма с дефолтным кодом-примером)
     */
    public function create()
    {
        return CmsResponse::page('packages/templite/cms/resources/js/entries/actions-edit.js', [
            'action' => null,
            'defaultCode' => $this->getDefaultActionCode(),
            'globalFieldDefinitions' => GlobalField::select('key', 'type', 'name')->whereNull('parent_id')->get(),
        ], ['title' => 'Новый экшн']);
    }

    /**
     * Получить дефолтный код-шаблон для нового action.
     */
    protected function getDefaultActionCode(): string
    {
        $stubPath = dirname(__DIR__, 4) . '/stubs/action.stub';

        if (file_exists($stubPath)) {
            return file_get_contents($stubPath);
        }

        return '';
    }
}
