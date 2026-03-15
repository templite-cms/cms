<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class FileFolder extends Model implements Exportable
{
    use HasExportable;
    protected $fillable = [
        'name',
        'parent_id',
        'order',
    ];

    // --- Relationships ---

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class, 'folder_id');
    }

    // --- Helpers ---

    /**
     * Get full path as array of names from root to this folder.
     */
    public function getPathNames(): array
    {
        $names = [$this->name];
        $current = $this;

        while ($current->parent_id) {
            $current = $current->parent ?? self::find($current->parent_id);
            if (!$current) break;
            array_unshift($names, $current->name);
        }

        return $names;
    }

    // --- Scopes ---

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    // --- Exportable ---

    public function getExportType(): string { return 'file_folder'; }
    public function getExportIdentifier(): string { return $this->getFullPath(); }

    public function getDependencies(): array
    {
        return $this->parent ? [$this->parent] : [];
    }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'parent_path' => $this->parent?->getFullPath(),
            'order' => $this->order,
        ];
    }

    public function getFullPath(): string
    {
        $parts = [];
        $current = $this;
        while ($current) {
            array_unshift($parts, $current->name);
            $current = $current->parent;
        }
        return implode('/', $parts);
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $parentId = null;
        if ($data['parent_path'] ?? null) {
            $parentId = $ctx->resolve('file_folder', $data['parent_path'])?->id;
        }
        return static::updateOrCreate(
            ['name' => $data['name'], 'parent_id' => $parentId],
            ['order' => $data['order'] ?? 0]
        );
    }
}
