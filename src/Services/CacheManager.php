<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Facades\Cache;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\PageBlock;

class CacheManager
{
    protected string $prefix = 'cms';
    protected ?string $driver = null;

    public function __construct()
    {
        $this->driver = config('cms.cache.driver');
    }

    /**
     * Получить кэш блока.
     */
    public function getBlockCache(PageBlock $pageBlock): ?string
    {
        if (!$pageBlock->cache_enabled) {
            return null;
        }

        $key = $this->getBlockCacheKey($pageBlock);

        return $this->store()->get($key);
    }

    /**
     * Сохранить кэш блока.
     */
    public function putBlockCache(PageBlock $pageBlock, string $html): void
    {
        if (!$pageBlock->cache_enabled) {
            return;
        }

        $key = $this->getBlockCacheKey($pageBlock);
        $ttl = config('cms.cache.ttl', 3600);

        $this->store()->put($key, $html, $ttl);
    }

    /**
     * Инвалидировать кэш конкретного блока.
     */
    public function invalidateBlock(PageBlock $pageBlock): void
    {
        $key = $this->getBlockCacheKey($pageBlock);
        $this->store()->forget($key);
    }

    /**
     * Инвалидировать кэш всех блоков страницы.
     */
    public function invalidatePage(Page $page): void
    {
        $pageBlocks = PageBlock::where('page_id', $page->id)
            ->where('cache_enabled', true)
            ->get();

        foreach ($pageBlocks as $pb) {
            $this->invalidateBlock($pb);
        }
    }

    /**
     * Инвалидировать кэш всех блоков определённого типа.
     *
     * Вызывается при обновлении кода блока (template/style/script).
     * Сбрасывает OPcache чтобы новый шаблон подхватился при validate_timestamps=0.
     */
    public function invalidateBlockType(int $blockId): void
    {
        $pageBlocks = PageBlock::where('block_id', $blockId)
            ->where('cache_enabled', true)
            ->get();

        foreach ($pageBlocks as $pb) {
            $this->invalidateBlock($pb);
        }

        // Сбросить compiled view и OPcache для изменённого шаблона блока
        $this->clearCompiledViews();
        $this->resetOpcache();
    }

    /**
     * Очистить кэш всех блоков и вернуть статистику.
     *
     * Помимо HTML-кэша, очищает compiled Blade views и сбрасывает OPcache,
     * чтобы изменения в template.blade.php блоков применились
     * (особенно при opcache.validate_timestamps=0 в production).
     */
    public function clearBlocks(): array
    {
        $count = $this->forgetAllBlockEntries();

        // Очистить compiled Blade views — без этого OPcache может отдавать старые шаблоны
        $this->clearCompiledViews();

        // Сбросить OPcache чтобы новые compiled views подхватились
        $this->resetOpcache();

        return ['cleared' => $count];
    }

    /**
     * Удалить cache-записи всех блоков (только HTML-кэш, без views/opcache).
     */
    protected function forgetAllBlockEntries(): int
    {
        $pageBlocks = PageBlock::where('cache_enabled', true)->get();
        $count = 0;

        foreach ($pageBlocks as $pb) {
            $this->invalidateBlock($pb);
            $count++;
        }

        return $count;
    }

    /**
     * Очистить скомпилированные SCSS файлы и вернуть статистику.
     */
    public function clearScss(): array
    {
        $dir = storage_path('cms/compiled');
        $count = 0;
        $totalSize = 0;

        if (is_dir($dir)) {
            $files = glob($dir . '/*.css');
            foreach ($files as $file) {
                $totalSize += filesize($file);
                @unlink($file);
                $count++;
            }
        }

        return ['files' => $count, 'size_bytes' => $totalSize];
    }

    /**
     * Очистить весь кэш CMS и вернуть статистику.
     */
    public function clearAll(): array
    {
        $blockCount = $this->forgetAllBlockEntries();
        $global = $this->invalidateGlobalFields();
        $scss = $this->clearScss();
        $views = $this->clearCompiledViews();
        $this->resetOpcache();

        return [
            'blocks' => ['cleared' => $blockCount],
            'global' => $global,
            'scss' => $scss,
            'views' => $views,
        ];
    }

    /**
     * Кэшировать глобальные поля.
     */
    public function getGlobalFields(): ?array
    {
        return $this->store()->get($this->prefix . ':global_fields');
    }

    /**
     * Сохранить кэш глобальных полей.
     */
    public function putGlobalFields(array $fields): void
    {
        $ttl = config('cms.cache.ttl', 3600);
        $this->store()->put($this->prefix . ':global_fields', $fields, $ttl);
    }

    /**
     * Получить кэш глобальных полей по произвольному ключу (для мультиязычности).
     */
    public function getGlobalFieldsByKey(string $cacheKey): ?array
    {
        return $this->store()->get($this->prefix . ':' . $cacheKey);
    }

    /**
     * Сохранить кэш глобальных полей по произвольному ключу (для мультиязычности).
     */
    public function putGlobalFieldsByKey(string $cacheKey, array $fields): void
    {
        $ttl = config('cms.cache.ttl', 3600);
        $this->store()->put($this->prefix . ':' . $cacheKey, $fields, $ttl);
    }

    /**
     * Инвалидировать кэш глобальных полей (все языковые варианты).
     */
    public function invalidateGlobalFields(): array
    {
        $count = 0;

        // Базовый ключ (дефолтный язык)
        if ($this->store()->forget($this->prefix . ':global_fields')) {
            $count++;
        }

        // Все языковые варианты
        try {
            $languages = \Templite\Cms\Models\Language::getCachedActive();
            foreach ($languages as $lang) {
                if ($this->store()->forget($this->prefix . ':global_fields_' . $lang->code)) {
                    $count++;
                }
            }
        } catch (\Throwable) {
            // Language может быть недоступен (миграции, свежая установка)
        }

        return ['cleared' => $count];
    }

    /**
     * Очистить compiled Blade views из storage/framework/views.
     *
     * При opcache.validate_timestamps=0 (production) OPcache кэширует
     * скомпилированные Blade-шаблоны навсегда. Удаление файлов заставляет
     * Blade создать новые файлы с новыми путями в OPcache.
     */
    public function clearCompiledViews(): array
    {
        $dir = config('view.compiled', storage_path('framework/views'));
        $count = 0;

        if (is_dir($dir)) {
            $files = glob($dir . '/*.php');
            foreach ($files as $file) {
                @unlink($file);
                $count++;
            }
        }

        return ['files' => $count];
    }

    /**
     * Сбросить OPcache (если доступен).
     *
     * Необходимо при opcache.validate_timestamps=0 (production),
     * иначе OPcache продолжит отдавать старые скомпилированные файлы.
     */
    public function resetOpcache(): bool
    {
        if (function_exists('opcache_reset')) {
            return opcache_reset();
        }

        return false;
    }

    /**
     * Генерация ключа кэша блока.
     */
    protected function getBlockCacheKey(PageBlock $pageBlock): string
    {
        return $this->prefix . ':' . $pageBlock->getCacheKeyString();
    }

    /**
     * Получить хранилище кэша.
     */
    protected function store()
    {
        return $this->driver ? Cache::store($this->driver) : Cache::store();
    }
}
