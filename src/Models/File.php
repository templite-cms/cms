<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class File extends Model implements Exportable
{
    use HasExportable;
    protected $fillable = [
        'name',
        'path',
        'disk',
        'size',
        'mime',
        'type',
        'parent_id',
        'alt',
        'title',
        'sizes',
        'meta',
        'folder_id',
    ];

    protected $casts = [
        'sizes' => 'json',
        'meta' => 'json',
        'size' => 'integer',
    ];

    // --- Relationships ---

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FileFolder::class, 'folder_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    // --- Accessors ---

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Получить URL файла определённого размера и формата.
     */
    public function url(?string $size = null, string $format = 'original'): string
    {
        if (!$size) {
            return $this->url;
        }

        $path = $this->sizes[$size][$format] ?? $this->sizes[$size]['original'] ?? null;

        return $path ? Storage::disk($this->disk)->url($path) : $this->url;
    }

    /**
     * Проверить наличие формата для размера.
     */
    public function hasFormat(?string $size, string $format): bool
    {
        return isset($this->sizes[$size][$format]);
    }

    /**
     * Сгенерировать srcset для определённого формата.
     */
    public function srcset(string $format = 'original'): string
    {
        return collect($this->sizes)
            ->filter(fn($data) => isset($data[$format]))
            ->map(fn($data, $size) =>
                Storage::disk($this->disk)->url($data[$format]) . ' ' . ($data['width'] ?? '') . 'w'
            )->implode(', ');
    }

    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    public function isDocument(): bool
    {
        return $this->type === 'document';
    }

    /**
     * Удалить файл вместе со всеми физическими файлами (ресайзы, форматы).
     */
    public function deleteWithFiles(): void
    {
        // Удалить все ресайзы
        foreach ($this->sizes ?? [] as $sizeData) {
            foreach ($sizeData as $key => $path) {
                if (!in_array($key, ['width', 'height'])) {
                    Storage::disk($this->disk)->delete($path);
                }
            }
        }

        // Удалить оригинал
        Storage::disk($this->disk)->delete($this->path);

        // Удалить дочерние файлы
        foreach ($this->children as $child) {
            $child->deleteWithFiles();
        }

        $this->delete();
    }

    // --- Scopes ---

    public function scopeImages($query)
    {
        return $query->where('type', 'image');
    }

    public function scopeVideos($query)
    {
        return $query->where('type', 'video');
    }

    public function scopeDocuments($query)
    {
        return $query->where('type', 'document');
    }

    public function scopeInFolder($query, ?int $folderId)
    {
        return $query->where('folder_id', $folderId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // --- Exportable ---

    public function getExportType(): string { return 'file'; }
    public function getExportIdentifier(): string { return $this->path; }

    public function getDependencies(): array
    {
        $deps = [];
        if ($this->folder) $deps[] = $this->folder;
        if ($this->parent) $deps[] = $this->parent;
        return $deps;
    }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'disk' => $this->disk,
            'size' => $this->size,
            'mime' => $this->mime,
            'type' => $this->type,
            'alt' => $this->alt,
            'title' => $this->title,
            'sizes' => $this->sizes,
            'meta' => $this->meta,
            'parent_path' => $this->parent?->path,
            'folder_path' => $this->folder?->getFullPath(),
        ];
    }

    public function getExportMediaFiles(): array
    {
        $files = [$this->path];
        if (is_array($this->sizes)) {
            foreach ($this->sizes as $size) {
                if (isset($size['original'])) $files[] = $size['original'];
                if (isset($size['webp'])) $files[] = $size['webp'];
            }
        }
        return $files;
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $folderId = null;
        if ($data['folder_path'] ?? null) {
            $folderId = $ctx->resolveId('file_folder', $data['folder_path']);
        }
        $parentId = null;
        if ($data['parent_path'] ?? null) {
            $parentId = $ctx->resolveId('file', $data['parent_path']);
        }
        return static::updateOrCreate(
            ['path' => $data['path']],
            [
                'name' => $data['name'],
                'disk' => $data['disk'],
                'size' => $data['size'],
                'mime' => $data['mime'],
                'type' => $data['type'],
                'alt' => $data['alt'],
                'title' => $data['title'],
                'sizes' => $data['sizes'],
                'meta' => $data['meta'],
                'folder_id' => $folderId,
                'parent_id' => $parentId,
            ]
        );
    }
}
