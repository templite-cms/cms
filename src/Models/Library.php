<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class Library extends Model implements Exportable
{
    use HasExportable;
    protected $fillable = [
        'name',
        'slug',
        'description',
        'sort_order',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // --- Relationships ---

    public function files(): HasMany
    {
        return $this->hasMany(LibraryFile::class)->ordered();
    }

    public function blocks(): BelongsToMany
    {
        return $this->belongsToMany(Block::class, 'block_library');
    }

    public function templatePages(): BelongsToMany
    {
        return $this->belongsToMany(TemplatePage::class, 'template_page_library');
    }

    // --- Accessors ---

    /**
     * Версия для бейджа в списках библиотек.
     *
     * Общей колонки version у библиотеки больше нет — версия своя у каждого
     * файла. Одну показываем, только если она одна на все файлы: иначе бейдж
     * выдавал бы версию случайного файла за версию всей библиотеки.
     *
     * Требует загруженной связи files, иначе каждый элемент списка сходит
     * в базу отдельно.
     */
    public function getVersionAttribute(): ?string
    {
        $versions = $this->files->pluck('version')->filter()->unique();

        return $versions->count() === 1 ? (string) $versions->first() : null;
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    // --- Exportable ---

    public function getExportType(): string { return 'library'; }
    public function getExportIdentifier(): string { return $this->slug; }
    public function getDependencies(): array { return []; }

    public function toExportArray(): array
    {
        $this->loadMissing('files');

        $storage = \Illuminate\Support\Facades\Storage::disk('public');

        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
            'files' => $this->files->sortBy('sort_order')->values()->map(fn ($file) => [
                'type' => $file->type,
                'source' => $file->source,
                // file_name используется для сопоставления с содержимым ZIP,
                // поэтому условие здесь то же, что в getCodeFilesForExport():
                // если файла на диске нет, он не попадёт и в архив, а имя в
                // meta.json обещало бы импорту несуществующее содержимое
                'file_name' => $file->source === 'local' && filled($file->path) && $storage->exists($file->path)
                    ? basename($file->path)
                    : null,
                // Подпись к файлу отдаём всегда: она отображаемая и от наличия
                // файла на диске не зависит
                'original_name' => $file->original_name,
                'url' => $file->source === 'cdn' ? $file->url : null,
                'version' => $file->version,
                'loading' => $file->loading,
                'media' => $file->media,
                'sort_order' => $file->sort_order,
            ])->all(),
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        // Импорт идёт мимо валидации контроллера, поэтому границу длины slug
        // приходится держать здесь: из неё складывается путь файла
        // cms/libraries/{slug}/{slug}-XXXXXXXX.js, а path — varchar(255).
        // Без этого импортированную библиотеку нельзя было бы переименовать
        // через API, где правило slug ограничено max:100
        $slug = mb_substr($data['slug'], 0, 100);

        $library = static::updateOrCreate(['slug' => $slug], [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'active' => $data['active'] ?? true,
        ]);

        $library->files()->delete();

        foreach (static::normalizeImportedFiles($data) as $index => $file) {
            $library->files()->create([
                // Для локальных файлов здесь лежит голое имя из ZIP;
                // importCodeFromZip() заменит его на реальный путь в storage
                'path' => ($file['source'] ?? 'local') === 'local' ? ($file['file_name'] ?? null) : null,
                'url' => ($file['source'] ?? 'local') === 'cdn' ? ($file['url'] ?? null) : null,
                'type' => $file['type'],
                'source' => $file['source'],
                'original_name' => $file['original_name'] ?? $file['file_name'] ?? null,
                'version' => $file['version'] ?? null,
                'loading' => $file['loading'] ?? null,
                'media' => $file['media'] ?? null,
                'sort_order' => $file['sort_order'] ?? $index,
            ]);
        }

        return $library;
    }

    /**
     * Приводит новый (files[]) и старый (js_file_name / css_cdn / load_strategy)
     * форматы экспорта к единому списку файлов.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function normalizeImportedFiles(array $data): array
    {
        if (isset($data['files']) && is_array($data['files'])) {
            return static::sanitizeImportedFiles($data['files']);
        }

        $version = $data['version'] ?? null;

        // v1.0 клал в js_file/css_file полный путь в storage, более поздний
        // экспорт — только имя файла в js_file_name/css_file_name
        $legacyCssFile = $data['css_file_name']
            ?? (isset($data['css_file']) ? basename($data['css_file']) : null);
        $legacyJsFile = $data['js_file_name']
            ?? (isset($data['js_file']) ? basename($data['js_file']) : null);

        $cdnPair = [['css', $data['css_cdn'] ?? null, 'url'], ['js', $data['js_cdn'] ?? null, 'url']];
        $localPair = [['css', $legacyCssFile, 'file_name'], ['js', $legacyJsFile, 'file_name']];

        [$primary, $fallback] = ($data['load_strategy'] ?? 'local') === 'cdn'
            ? [$cdnPair, $localPair]
            : [$localPair, $cdnPair];

        // Та же логика, что в миграции create_library_files_table: вторая пара
        // подхватывается, только если первая пуста. Старый fromImportArray()
        // писал в load_strategy 'defer' — значение вне пары local|cdn, и без
        // фолбэка такой архив потерял бы свои единственные CDN-ссылки.
        $candidates = array_filter($primary, fn ($c) => !empty($c[1]));
        if (!$candidates) {
            $candidates = array_filter($fallback, fn ($c) => !empty($c[1]));
        }

        $files = [];

        foreach ($candidates as [$type, $value, $key]) {
            $files[] = [
                'type' => $type,
                // Источник берём из колонки, а не из load_strategy: при
                // фолбэке они расходятся
                'source' => $key === 'url' ? 'cdn' : 'local',
                $key => $value,
                'version' => $version,
                'sort_order' => count($files),
            ];
        }

        return $files;
    }

    /**
     * Отсев и усечение записей files[]: они приходят из чужого архива вообще
     * без валидации, а ImportService держит весь ZIP в одной транзакции —
     * любая ошибка вставки откатила бы вместе с библиотекой все остальные
     * сущности архива. Поэтому запись с неизвестным type/source молча
     * выбрасывается, а строки режутся по длине своих колонок.
     *
     * Старый формат через это не проходит: там type и source выводятся из
     * самих колонок и заведомо корректны.
     *
     * @param  array<int, mixed>  $files
     * @return array<int, array<string, mixed>>
     */
    protected static function sanitizeImportedFiles(array $files): array
    {
        $result = [];

        foreach ($files as $file) {
            if (!is_array($file)
                || !in_array($file['type'] ?? null, LibraryFile::TYPES, true)
                || !in_array($file['source'] ?? null, LibraryFile::SOURCES, true)
            ) {
                continue;
            }

            // Подпись файла: если своей нет, показываем имя из архива
            $originalName = $file['original_name'] ?? null;
            if (!is_scalar($originalName)) {
                $originalName = $file['file_name'] ?? null;
            }

            $loading = $file['loading'] ?? null;
            $sortOrder = $file['sort_order'] ?? null;

            $result[] = [
                'type' => $file['type'],
                'source' => $file['source'],
                // Имя уезжает в path, а это varchar(255). Для наших архивов
                // усечение — пустая операция ({slug≤100}-{8}.{ext}), у чужого
                // же вместо падения вставки получится запись, которой не
                // нашлось файла: importCodeFromZip() обнулит ей path
                'file_name' => static::clipToColumn($file['file_name'] ?? null, 255),
                'original_name' => static::clipToColumn($originalName, 255),
                'url' => static::clipToColumn($file['url'] ?? null, 500),
                'version' => static::clipToColumn($file['version'] ?? null, 255),
                'loading' => in_array($loading, LibraryFile::LOADINGS, true) ? $loading : null,
                'media' => static::clipToColumn($file['media'] ?? null, 50),
                'sort_order' => is_numeric($sortOrder) ? (int) $sortOrder : count($result),
            ];
        }

        return $result;
    }

    /**
     * Усечение строки под длину колонки. Нескаляр — это null: массив из
     * чужого json уронил бы mb_substr в TypeError.
     */
    protected static function clipToColumn(mixed $value, int $limit): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        return mb_substr((string) $value, 0, $limit);
    }

    /**
     * Локальные файлы библиотеки для экспорта.
     *
     * @return array<string, string> имя файла в ZIP => абсолютный путь на диске
     */
    public function getCodeFilesForExport(): array
    {
        $this->loadMissing('files');

        $files = [];
        $storage = \Illuminate\Support\Facades\Storage::disk('public');

        foreach ($this->files as $file) {
            if ($file->source !== 'local' || blank($file->path) || !$storage->exists($file->path)) {
                continue;
            }

            $files[basename($file->path)] = $storage->path($file->path);
        }

        return $files;
    }

    /**
     * Импорт локальных файлов из ZIP-архива.
     * Вызывается ImportService после fromImportArray().
     */
    public static function importCodeFromZip(self $library, \ZipArchive $zip, string $zipPrefix): void
    {
        $storage = \Illuminate\Support\Facades\Storage::disk('public');
        // basename по той же причине, что и в LibraryFileSync: slug приходит
        // из meta.json архива и может содержать разделители пути
        $dir = 'cms/libraries/' . basename($library->slug);

        $library->load('files');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!str_starts_with($name, $zipPrefix) || str_ends_with($name, '/') || str_ends_with($name, 'meta.json')) {
                continue;
            }

            $fileName = basename($name);

            $match = $library->files->first(
                fn ($file) => $file->source === 'local' && filled($file->path) && basename($file->path) === $fileName
            );

            // Файл, которому не нашлось записи, на диск не кладём: убрать его
            // потом будет некому — LibraryFileSync стирает только файлы снятых
            // записей, и лишний остался бы в каталоге библиотеки навсегда
            if (!$match) {
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }

            $targetPath = "{$dir}/{$fileName}";
            $storage->put($targetPath, $content);
            $match->update(['path' => $targetPath]);
        }

        // У сопоставленных записей path уже заменён на путь в storage. Если он
        // так и остался голым именем из meta.json, значит файла в архиве не
        // было. Оставлять такое нельзя: resolvedUrl() построил бы ссылку на
        // /storage/{имя}, в редакторе файл выглядел бы загруженным, а на
        // странице отдавал бы 404. Пустой path — честное «Файл не выбран».
        foreach ($library->files as $file) {
            if ($file->source === 'local' && filled($file->path) && !str_starts_with($file->path, "{$dir}/")) {
                $file->update(['path' => null]);
            }
        }
    }
}
