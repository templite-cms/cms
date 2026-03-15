<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Artisan: cms:make-action {name}
 *
 * Создание нового Action из шаблона.
 * Генерирует PHP-класс, реализующий BlockActionInterface.
 */
class MakeActionCommand extends Command
{
    protected $signature = 'cms:make-action
        {name : Название action (PascalCase или kebab-case)}
        {--storage : Создать в storage/cms/actions вместо app/Cms/Actions}';

    protected $description = 'Создать новый Action для блока CMS';

    public function handle(): int
    {
        $name = $this->argument('name');
        $className = Str::studly($name);
        $slug = Str::kebab($name);

        if ($this->option('storage')) {
            $path = storage_path("cms/actions/{$className}.php");
            $namespace = null; // Файл будет загружен как standalone
        } else {
            $path = app_path("Cms/Actions/{$className}.php");
            $namespace = 'App\\Cms\\Actions';
        }

        if (File::exists($path)) {
            $this->error("Action '{$className}' уже существует: {$path}");
            return self::FAILURE;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->getStub($className, $slug, $namespace));

        $this->info("Action '{$className}' создан: {$path}");

        return self::SUCCESS;
    }

    protected function getStub(string $className, string $slug, ?string $namespace): string
    {
        $namespaceDecl = $namespace
            ? "namespace {$namespace};\n\n"
            : '';

        return <<<PHP
<?php

{$namespaceDecl}use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;

/**
 * Action: {$className}
 * Slug: {$slug}
 */
class {$className} implements BlockActionInterface
{
    /**
     * Входные параметры action.
     * Ключ — имя параметра, значение — тип (string, int, array, и т.д.)
     */
    public function params(): array
    {
        return [
            // 'email' => 'string',
            // 'message' => 'string',
        ];
    }

    /**
     * Возвращаемые данные action.
     * Ключ — имя, значение — тип.
     */
    public function returns(): array
    {
        return [
            'success' => 'boolean',
            'message' => 'string',
        ];
    }

    /**
     * Выполнение action.
     *
     * @param array \$params Параметры из конфигурации + данные формы
     * @param ActionContext \$context Контекст выполнения (page, request, global, blockData)
     * @return array Результат выполнения
     */
    public function handle(array \$params, ActionContext \$context): array
    {
        // Ваша логика здесь

        return [
            'success' => true,
            'message' => 'Action {$className} выполнен.',
        ];
    }
}
PHP;
    }
}
