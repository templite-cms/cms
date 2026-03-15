<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class BlockPreset extends Model implements Exportable
{
    use HasExportable;
    protected $fillable = [
        'name',
        'slug',
        'description',
        'block_id',
        'type',
        'data',
        'screen',
        'order',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    // --- Relationships ---

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function screenFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'screen');
    }

    public function pageBlocks(): HasMany
    {
        return $this->hasMany(PageBlock::class, 'preset_id');
    }

    // --- Scopes ---

    public function scopeGlobal($query)
    {
        return $query->where('type', 'global');
    }

    public function scopeLocal($query)
    {
        return $query->where('type', 'local');
    }

    public function scopeForBlock($query, int $blockId)
    {
        return $query->where('block_id', $blockId);
    }

    // --- Exportable ---

    public function getExportType(): string { return 'preset'; }
    public function getExportIdentifier(): string { return $this->slug; }

    public function getDependencies(): array
    {
        $deps = [];
        if ($this->block) {
            $deps[] = $this->block;
        }
        if ($this->screenFile) {
            $deps[] = $this->screenFile;
        }
        return $deps;
    }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'block_slug' => $this->block?->slug,
            'type' => $this->type,
            'data' => $this->data,
            'order' => $this->order,
            'screen_path' => $this->screenFile?->path,
        ];
    }

    public function getExportMediaFiles(): array
    {
        return $this->screenFile ? $this->screenFile->getExportMediaFiles() : [];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $blockId = $ctx->resolveId('block', $data['block_slug'] ?? '');
        $screenId = isset($data['screen_path'])
            ? $ctx->resolveId('file', $data['screen_path']) : null;

        return static::updateOrCreate(
            ['slug' => $data['slug']],
            [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'block_id' => $blockId,
                'type' => $data['type'] ?? 'local',
                'data' => $data['data'] ?? null,
                'order' => $data['order'] ?? 0,
                'screen' => $screenId,
            ]
        );
    }
}
