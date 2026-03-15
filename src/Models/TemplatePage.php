<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;
use Templite\Cms\Traits\HasFieldable;
use Templite\Cms\Traits\HasFiles;

class TemplatePage extends Model implements Exportable
{
    use HasFieldable, HasFiles, HasExportable;

    protected $fillable = [
        'name',
        'slug',
        'settings',
        'screen',
    ];

    protected $casts = [
        'settings' => 'json',
    ];

    protected static function booted(): void
    {
        static::deleting(function (TemplatePage $template) {
            $template->fieldDefinitions()->each(function ($field) {
                $field->children()->delete();
                $field->delete();
            });
            $template->fieldSections()->delete();
            $template->fieldTabs()->delete();
        });
    }

    public function tabs(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->fieldTabs();
    }

    public function sections(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->fieldSections();
    }

    public function fields(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->fieldDefinitions();
    }

    public function rootFields(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->rootFieldDefinitions();
    }

    public function pageTypes(): HasMany
    {
        return $this->hasMany(PageType::class, 'template_page_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'template_page_id');
    }

    public function libraries(): BelongsToMany
    {
        return $this->belongsToMany(Library::class, 'template_page_library');
    }

    // --- Exportable ---

    public function getExportType(): string { return 'template'; }
    public function getExportIdentifier(): string { return $this->slug; }

    public function getDependencies(): array
    {
        $deps = [];
        foreach ($this->libraries as $lib) {
            $deps[] = $lib;
        }
        if ($this->screenshot) {
            $deps[] = $this->screenshot;
        }
        return $deps;
    }

    public function toExportArray(): array
    {
        // Используем Block для экспорта полей (exportFields -- public метод)
        $block = new Block();

        $tabs = $this->tabs()
            ->orderBy('order')
            ->with(['sections.fields.children', 'fields.children'])
            ->get();

        $exportedTabs = $tabs->map(function ($tab) use ($block) {
            return [
                'name' => $tab->name,
                'order' => $tab->order,
                'columns' => $tab->columns,
                'column_widths' => $tab->column_widths,
                'sections' => $tab->sections->sortBy('order')->map(fn ($s) => [
                    'name' => $s->name,
                    'order' => $s->order,
                    'column_index' => $s->column_index,
                    'fields' => $block->exportFields($s->fields),
                ])->values()->toArray(),
                'fields' => $block->exportFields(
                    $tab->fields->whereNull('block_section_id')
                ),
            ];
        })->toArray();

        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'settings' => $this->settings,
            'screen_path' => $this->screenshot?->path,
            'library_slugs' => $this->libraries->pluck('slug')->toArray(),
            'tabs' => $exportedTabs,
        ];
    }

    public function getExportMediaFiles(): array
    {
        return $this->screenshot ? $this->screenshot->getExportMediaFiles() : [];
    }

    /**
     * Файлы кода шаблона для экспорта в ZIP (реальные файлы, не JSON-строки).
     *
     * @return array<string, string> имя файла в ZIP => абсолютный путь на диске
     */
    public function getCodeFilesForExport(): array
    {
        $basePath = storage_path("cms/templates/" . basename($this->slug));
        if (!is_dir($basePath)) {
            return [];
        }

        $files = [];
        foreach (['template.blade.php', 'style.scss', 'script.js'] as $file) {
            $fullPath = "{$basePath}/{$file}";
            if (file_exists($fullPath)) {
                $files[$file] = $fullPath;
            }
        }
        return $files;
    }

    /**
     * Импорт файлов кода из ZIP-архива.
     * Вызывается ImportService после fromImportArray().
     */
    public static function importCodeFromZip(self $template, \ZipArchive $zip, string $zipPrefix): void
    {
        $basePath = storage_path("cms/templates/" . basename($template->slug));

        foreach (['template.blade.php', 'style.scss', 'script.js'] as $file) {
            $content = $zip->getFromName("{$zipPrefix}{$file}");
            if ($content !== false) {
                if (!is_dir($basePath)) {
                    mkdir($basePath, 0755, true);
                }
                file_put_contents("{$basePath}/{$file}", $content);
            }
        }
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $screenId = isset($data['screen_path'])
            ? $ctx->resolveId('file', $data['screen_path']) : null;

        $template = static::updateOrCreate(
            ['slug' => $data['slug']],
            [
                'name' => $data['name'],
                'settings' => $data['settings'] ?? null,
                'screen' => $screenId,
            ]
        );

        // Синхронизация библиотек
        $libIds = collect($data['library_slugs'] ?? [])
            ->map(fn ($slug) => $ctx->resolveId('library', $slug))
            ->filter()->toArray();
        $template->libraries()->sync($libIds);

        // Импорт tabs/sections/fields -- переиспользуем статический метод Block
        Block::importFieldableTabs($template, $data['tabs'] ?? []);

        // Импорт файлов кода
        if ($data['code'] ?? null) {
            $basePath = storage_path("cms/templates/" . basename($template->slug));
            if (!is_dir($basePath)) {
                mkdir($basePath, 0755, true);
            }
            if ($data['code']['template'] ?? null) {
                file_put_contents("{$basePath}/template.blade.php", $data['code']['template']);
            }
            if ($data['code']['style'] ?? null) {
                file_put_contents("{$basePath}/style.scss", $data['code']['style']);
            }
            if ($data['code']['script'] ?? null) {
                file_put_contents("{$basePath}/script.js", $data['code']['script']);
            }
        }

        return $template;
    }
}
