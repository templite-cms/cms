<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
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
    public function index(): Response
    {
        return Inertia::render('Actions/Index', [
            'actions' => Action::withCount('blockActions')->orderBy('name')->get(),
            'registryActions' => $this->actionRegistry->all(),
        ]);
    }

    /**
     * Редактирование action.
     * Экран: Actions/Edit
     */
    public function edit(int $id): Response
    {
        $action = Action::with('blockActions.block')->findOrFail($id);

        $action->setAttribute('code', $this->resolveActionCode($action));

        return Inertia::render('Actions/Edit', [
            'action' => $action,
            'globalFieldDefinitions' => GlobalField::select('key', 'type', 'name')->whereNull('parent_id')->get(),
        ]);
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
     * Экран: Actions/Edit (пустая форма)
     */
    public function create(): Response
    {
        return Inertia::render('Actions/Edit', [
            'action' => null,
            'globalFieldDefinitions' => GlobalField::select('key', 'type', 'name')->whereNull('parent_id')->get(),
        ]);
    }
}
