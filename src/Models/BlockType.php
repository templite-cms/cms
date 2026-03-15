<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class BlockType extends Model implements Exportable
{
    use HasExportable;
    protected $fillable = [
        'name',
        'slug',
        'description',
        'type',
        'order',
    ];

    protected $casts = [
        'type' => 'integer',
    ];

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'block_type_id')->orderBy('order');
    }

    public function scopeByType($query, int $type)
    {
        return $query->where('type', $type);
    }

    // --- Exportable ---

    public function getExportType(): string { return 'block_type'; }
    public function getExportIdentifier(): string { return $this->slug; }
    public function getDependencies(): array { return []; }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'type' => $this->type,
            'order' => $this->order,
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        return static::updateOrCreate(['slug' => $data['slug']], $data);
    }
}
