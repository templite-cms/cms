<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Models\Action;
use Templite\Cms\Models\Page;
use Templite\Cms\Services\ActionRegistry;

class RunActionCommand extends Command
{
    protected $signature = 'cms:run-action {action : Slug или ID action}';
    protected $description = 'Выполнить Action по slug или ID';

    public function handle(ActionRegistry $actionRegistry): int
    {
        $identifier = $this->argument('action');

        // Найти action по ID или slug
        $action = is_numeric($identifier)
            ? Action::find((int) $identifier)
            : Action::where('slug', $identifier)->first();

        if (!$action) {
            $this->error("Action '{$identifier}' не найден.");
            return self::FAILURE;
        }

        $this->info("Запуск action: {$action->name} ({$action->slug})");

        // Резолвим экземпляр action
        $instance = $actionRegistry->resolve($action->slug);

        if (!$instance) {
            $this->error("Не удалось загрузить action '{$action->slug}'. Проверьте файл и валидацию кода.");
            return self::FAILURE;
        }

        // Создаём минимальный контекст для CLI
        $page = Page::first();
        if (!$page) {
            $this->error('Нет ни одной страницы в БД. Создайте хотя бы одну страницу.');
            return self::FAILURE;
        }

        $global = [];
        if (app()->bound('global_fields')) {
            $global = app('global_fields');
        }

        $context = new ActionContext(
            page: $page,
            request: Request::create('/', 'GET'),
            global: is_array($global) ? $global : [],
            blockData: [],
        );

        // Собираем параметры по умолчанию
        $defaults = [];
        foreach ($instance->params() as $key => $config) {
            if (isset($config['default'])) {
                $defaults[$key] = $config['default'];
            }
        }

        try {
            $result = $instance->handle($defaults, $context);

            $this->info('Результат:');
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Ошибка выполнения: {$e->getMessage()}");
            $this->line($e->getTraceAsString());

            return self::FAILURE;
        }
    }
}
