<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class PageType extends Model implements Exportable
{
    use HasExportable;

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'template_page_id',
        'settings',
    ];

    protected $casts = [
        'settings' => 'json',
    ];

    public function templatePage(): BelongsTo
    {
        return $this->belongsTo(TemplatePage::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(PageTypeAttribute::class)->orderBy('order');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(Page::class, 'type_id');
    }

    // --- Exportable ---

    public function getExportType(): string { return 'page_type'; }
    public function getExportIdentifier(): string { return $this->slug; }

    public function getDependencies(): array
    {
        return $this->templatePage ? [$this->templatePage] : [];
    }

    public function toExportArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'template_page_slug' => $this->templatePage?->slug,
            'settings' => $this->settings,
            'attributes' => $this->attributes()->get()->sortBy('order')->map(fn ($a) => [
                'name' => $a->name,
                'key' => $a->key,
                'type' => $a->type,
                'options' => $a->options,
                'filterable' => $a->filterable,
                'sortable' => $a->sortable,
                'required' => $a->required,
                'order' => $a->order,
            ])->values()->toArray(),
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        $templatePageId = isset($data['template_page_slug'])
            ? $ctx->resolveId('template', $data['template_page_slug']) : null;

        $pageType = static::updateOrCreate(
            ['slug' => $data['slug']],
            [
                'name' => $data['name'],
                'icon' => $data['icon'] ?? null,
                'template_page_id' => $templatePageId,
                'settings' => $data['settings'] ?? null,
            ]
        );

        // Re-create attributes
        $pageType->attributes()->delete();
        foreach ($data['attributes'] ?? [] as $attrData) {
            $pageType->attributes()->create($attrData);
        }

        return $pageType;
    }
}
