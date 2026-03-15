<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Artisan: cms:make-component {name}
 *
 * Создание нового Blade-компонента CMS.
 * Генерирует index.blade.php, style.scss и component.json.
 */
class MakeComponentCommand extends Command
{
    protected $signature = 'cms:make-component
        {name : Название компонента (PascalCase или kebab-case)}
        {--storage : Создать в storage/cms/components вместо app/Cms/Components}';

    protected $description = 'Создать новый Blade-компонент CMS';

    public function handle(): int
    {
        $name = $this->argument('name');
        $slug = Str::kebab($name);
        $className = Str::studly($name);

        $basePath = $this->option('storage')
            ? storage_path("cms/components/{$slug}")
            : app_path("Cms/Components/{$className}");

        if (File::isDirectory($basePath)) {
            $this->error("Компонент '{$slug}' уже существует: {$basePath}");
            return self::FAILURE;
        }

        File::makeDirectory($basePath, 0755, true);

        // index.blade.php (Laravel anonymous component convention)
        File::put("{$basePath}/index.blade.php", $this->getTemplateStub($slug, $className));

        // style.scss
        File::put("{$basePath}/style.scss", $this->getStyleStub($slug));

        // component.json (метаданные)
        File::put("{$basePath}/component.json", $this->getComponentJsonStub($className, $slug));

        $this->info("Компонент '{$className}' создан: {$basePath}");
        $this->line('  - index.blade.php');
        $this->line('  - style.scss');
        $this->line('  - component.json');

        return self::SUCCESS;
    }

    protected function getTemplateStub(string $slug, string $className): string
    {
        return <<<BLADE
{{-- Компонент: {$className} --}}
{{-- slug: {$slug} --}}
{{-- Использование: <x-cms::{$slug} /> --}}

@props([
    // Определите пропсы: 'key' => 'default'
])

<div class="cms-component-{$slug}">
    {{ \$slot ?? '' }}
</div>
BLADE;
    }

    protected function getStyleStub(string $slug): string
    {
        return <<<SCSS
// Компонент: {$slug}

.cms-component-{$slug} {
    // Стили компонента
}
SCSS;
    }

    protected function getComponentJsonStub(string $className, string $slug): string
    {
        return json_encode([
            'name' => $className,
            'slug' => $slug,
            'description' => "Компонент {$className}",
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
