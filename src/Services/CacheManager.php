<?php

namespace Templite\Cms\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
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
        // Новая версия экземпляра делает недоступными все его записи; старые истекут по TTL.
        $this->store()->forget($this->blockVersionKey($pageBlock));
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
     * Сбросить HTML-кэш всех блоков (без views/opcache).
     *
     * @return int Сколько экземпляров блоков было с включённым кэшем
     */
    protected function forgetAllBlockEntries(): int
    {
        $this->store()->forget($this->blocksGenerationKey());

        return PageBlock::where('cache_enabled', true)->count();
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
     * Инвалидировать кэш глобальных полей.
     */
    public function invalidateGlobalFields(): array
    {
        // Блоки выводят $global — их HTML устарел вместе с настройками.
        $this->forgetAllBlockEntries();

        $cleared = $this->store()->forget($this->prefix . ':global_fields') ? 1 : 0;

        return ['cleared' => $cleared];
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
     * Ключ HTML-кэша экземпляра блока.
     *
     * HTML зависит от экземпляра, а не от типа блока: два одинаковых блока на
     * странице с разными данными кэшируются раздельно.
     */
    protected function getBlockCacheKey(PageBlock $pageBlock): string
    {
        $parts = [
            $this->prefix, 'block', $pageBlock->id,
            $this->token($this->blocksGenerationKey()),
            $this->token($this->blockVersionKey($pageBlock)),
        ];

        if ($pageBlock->cache_key) {
            $parts[] = $pageBlock->cache_key;
        }

        return implode(':', $parts);
    }

    protected function blockVersionKey(PageBlock $pageBlock): string
    {
        return "{$this->prefix}:block:{$pageBlock->id}:version";
    }

    /**
     * Поколение HTML-кэша всех блоков: его смена делает недоступными все записи разом.
     */
    protected function blocksGenerationKey(): string
    {
        return "{$this->prefix}:blocks:generation";
    }

    /**
     * Случайная метка, живущая до сброса ключа.
     */
    protected function token(string $key): string
    {
        return $this->store()->rememberForever($key, fn () => Str::random(8));
    }

    /**
     * Получить хранилище кэша.
     */
    protected function store()
    {
        return $this->driver ? Cache::store($this->driver) : Cache::store();
    }
}
