<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class Language extends Model implements Exportable
{
    use HasExportable;

    protected static ?Collection $cachedActive = null;

    protected $fillable = [
        'code',
        'name',
        'is_default',
        'is_active',
        'order',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('id');
    }

    /**
     * Получить кэшированную коллекцию активных языков.
     *
     * Использует двухуровневое кэширование:
     * 1. cache() — персистентный кэш (Redis/file), бессрочный
     * 2. static::$cachedActive — in-memory кэш на время запроса
     */
    public static function getCachedActive(): Collection
    {
        if (static::$cachedActive !== null) {
            return static::$cachedActive;
        }

        $rows = cache()->rememberForever('cms:languages_active', function () {
            return static::active()->ordered()->get()->toArray();
        });

        $models = new Collection();
        foreach ($rows as $attributes) {
            $model = (new static())->forceFill($attributes);
            $model->syncOriginal();
            $models->push($model);
        }

        static::$cachedActive = $models;

        return static::$cachedActive;
    }

    /**
     * Сбросить кэш языков (персистентный + in-memory).
     */
    public static function clearCache(): void
    {
        cache()->forget('cms:languages_active');
        static::$cachedActive = null;
    }

    public static function getDefault(): ?self
    {
        return static::getCachedActive()->firstWhere('is_default', true);
    }

    public static function findByCode(string $code): ?self
    {
        return static::getCachedActive()->firstWhere('code', $code);
    }

    public static function activeCodes(): array
    {
        return static::getCachedActive()->pluck('code')->toArray();
    }

    // --- Exportable ---

    public function getExportType(): string { return 'language'; }
    public function getExportIdentifier(): string { return $this->code; }
    public function getDependencies(): array { return []; }

    public function toExportArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'order' => $this->order,
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        return static::updateOrCreate(['code' => $data['code']], $data);
    }
}
