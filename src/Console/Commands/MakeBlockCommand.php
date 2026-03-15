<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Artisan: cms:make-block {name}
 *
 * Создание нового блока из шаблона (stub).
 * Генерирует: template.blade.php, style.scss, script.js
 */
class MakeBlockCommand extends Command
{
    protected $signature = 'cms:make-block
        {name : Название блока (PascalCase или kebab-case)}
        {--storage : Создать в storage/cms/blocks вместо app/Cms/Blocks}
        {--type= : Тип блока (slug)}';

    protected $description = 'Создать новый блок CMS';

    public function handle(): int
    {
        $name = $this->argument('name');
        $slug = Str::kebab($name);
        $className = Str::studly($name);

        $basePath = $this->option('storage')
            ? storage_path("cms/blocks/{$slug}")
            : app_path("Cms/Blocks/{$className}");

        if (File::isDirectory($basePath)) {
            $this->error("Блок '{$slug}' уже существует: {$basePath}");
            return self::FAILURE;
        }

        File::makeDirectory($basePath, 0755, true);

        // template.blade.php
        File::put("{$basePath}/template.blade.php", $this->getTemplateStub($slug, $className));

        // style.scss
        File::put("{$basePath}/style.scss", $this->getStyleStub($slug));

        // script.js
        File::put("{$basePath}/script.js", $this->getScriptStub($slug));

        // block.json (метаданные)
        File::put("{$basePath}/block.json", $this->getBlockJsonStub($className, $slug));

        $this->info("Блок '{$className}' создан: {$basePath}");
        $this->line('  - template.blade.php');
        $this->line('  - style.scss');
        $this->line('  - script.js');
        $this->line('  - block.json');

        return self::SUCCESS;
    }

    protected function getTemplateStub(string $slug, string $className): string
    {
        return <<<BLADE
{{-- Блок: {$className} --}}
{{-- slug: {$slug} --}}
{{-- Доступные переменные: \$data (поля блока), \$global (глобальные поля), \$page (текущая страница) --}}

<div class="block-{$slug}">
    <div class="block-{$slug}__container">
        @if(!empty(\$data['title']))
            <h2 class="block-{$slug}__title">{{ \$data['title'] }}</h2>
        @endif

        @if(!empty(\$data['text']))
            <div class="block-{$slug}__text">
                {!! \$data['text'] !!}
            </div>
        @endif
    </div>
</div>
BLADE;
    }

    protected function getStyleStub(string $slug): string
    {
        return <<<SCSS
// Блок: {$slug}

.block-{$slug} {
    padding: 60px 0;

    &__container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }

    &__title {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 1.5rem;
    }

    &__text {
        font-size: 1rem;
        line-height: 1.6;
    }
}
SCSS;
    }

    protected function getScriptStub(string $slug): string
    {
        return <<<JS
// Блок: {$slug}
// Этот файл подключается автоматически при рендере блока.

document.addEventListener('DOMContentLoaded', function () {
    const blocks = document.querySelectorAll('.block-{$slug}');
    blocks.forEach(function (block) {
        // Инициализация блока
    });
});
JS;
    }

    protected function getBlockJsonStub(string $className, string $slug): string
    {
        $type = $this->option('type') ?? 'content';

        return json_encode([
            'name' => $className,
            'slug' => $slug,
            'type' => $type,
            'description' => "Блок {$className}",
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
