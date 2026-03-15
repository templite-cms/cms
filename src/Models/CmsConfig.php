<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Templite\Cms\Concerns\HasExportable;
use Templite\Cms\Contracts\Exportable;
use Templite\Cms\Services\ImportExport\ImportContext;

class CmsConfig extends Model implements Exportable
{
    use HasExportable;
    protected $table = 'cms_config';

    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'order',
    ];

    /**
     * In-memory кэш всех конфигов (key => ['value' => ..., 'type' => ...]).
     */
    protected static ?array $cache = null;

    /**
     * Загрузить все конфиги из Redis-кэша, при промахе — из БД одним запросом.
     *
     * @return array<string, array{value: string|null, type: string}>
     */
    protected static function loadAll(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        try {
            static::$cache = cache()->remember('cms:config_all', null, function () {
                return static::all()
                    ->keyBy('key')
                    ->map(fn ($item) => [
                        'value' => $item->value,
                        'type' => $item->type,
                    ])
                    ->toArray();
            });
        } catch (\Throwable) {
            // Table may not exist yet (fresh install) or cache unavailable
            static::$cache = [];
        }

        return static::$cache;
    }

    /**
     * Сбросить кэш конфигов (Redis + in-memory).
     */
    public static function clearCache(): void
    {
        cache()->forget('cms:config_all');
        static::$cache = null;
    }

    /**
     * Получить значение настройки с автокастом по типу.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $all = static::loadAll();

        if (!isset($all[$key]) || $all[$key]['value'] === null) {
            return $default;
        }

        $value = $all[$key]['value'];
        $type = $all[$key]['type'];

        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Get the admin URL prefix with fallback chain: DB → ENV → default.
     */
    public static function getAdminUrl(): string
    {
        try {
            $dbValue = static::getValue('admin_url');
            if ($dbValue !== null && $dbValue !== '') {
                return trim($dbValue, '/');
            }
        } catch (\Throwable) {
            // Table may not exist yet (fresh install)
        }

        return env('CMS_ADMIN_URL', 'cms');
    }

    /**
     * Установить значение настройки.
     */
    public static function setValue(string $key, mixed $value): void
    {
        $config = static::where('key', $key)->first();

        if (!$config) {
            return;
        }

        $storedValue = match ($config->type) {
            'boolean' => $value ? '1' : '0',
            'json' => is_string($value) ? $value : json_encode($value),
            default => (string) $value,
        };

        $config->update(['value' => $storedValue]);

        static::clearCache();
    }

    // --- Exportable ---

    public function getExportType(): string { return 'cms_config'; }
    public function getExportIdentifier(): string { return $this->key; }
    public function getDependencies(): array { return []; }

    public function toExportArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'type' => $this->type,
            'group' => $this->group,
            'label' => $this->label,
            'description' => $this->description,
            'order' => $this->order,
        ];
    }

    public static function fromImportArray(array $data, ImportContext $ctx): static
    {
        return static::updateOrCreate(['key' => $data['key']], $data);
    }
}
