<?php

namespace Templite\Cms\Services;

use Templite\Cms\Models\Block;
use Templite\Cms\Models\BlockCodeVersion;

class BlockCodeVersionService
{
    /** Сколько версий держим на блок. */
    public const KEEP = 50;

    public function __construct(private readonly BlockRenderer $blockRenderer)
    {
    }

    /**
     * Прочитать код блока с диска.
     *
     * @return array{template: string, style: string, script: string}
     */
    public function readFromDisk(Block $block): array
    {
        $path = $this->blockRenderer->resolveBlockPath($block);

        return $path ? $this->readFrom($path) : ['template' => '', 'style' => '', 'script' => ''];
    }

    /**
     * Прочитать код блока из папки.
     *
     * @return array{template: string, style: string, script: string}
     */
    public function readFrom(string $path): array
    {
        $code = ['template' => '', 'style' => '', 'script' => ''];

        if (file_exists($path.'/template.blade.php')) {
            $code['template'] = (string) file_get_contents($path.'/template.blade.php');
        }
        if (file_exists($path.'/style.scss')) {
            $code['style'] = (string) file_get_contents($path.'/style.scss');
        } elseif (file_exists($path.'/style.css')) {
            $code['style'] = (string) file_get_contents($path.'/style.css');
        }
        if (file_exists($path.'/script.js')) {
            $code['script'] = (string) file_get_contents($path.'/script.js');
        }

        return $code;
    }

    /**
     * Снимок текущего состояния диска как первая версия.
     * Без него первая же правка сотрёт написанный руками код безвозвратно.
     */
    public function ensureInitialVersion(Block $block): void
    {
        if (BlockCodeVersion::where('block_id', $block->id)->exists()) {
            return;
        }

        $code = $this->readFromDisk($block);

        if ($code['template'] === '' && $code['style'] === '' && $code['script'] === '') {
            return;
        }

        $this->store($block, $code, 'initial');
    }

    /**
     * Записать версию и подчистить старые.
     *
     * @param  array{template?: ?string, style?: ?string, script?: ?string}  $code
     */
    public function store(Block $block, array $code, string $source, ?int $userId = null): BlockCodeVersion
    {
        $version = BlockCodeVersion::create([
            'block_id' => $block->id,
            'template' => $code['template'] ?? null,
            'style' => $code['style'] ?? null,
            'script' => $code['script'] ?? null,
            'user_id' => $userId ?? auth()->id(),
            'source' => $source,
            'created_at' => now(),
        ]);

        $this->prune($block);

        return $version;
    }

    private function prune(Block $block): void
    {
        $keepIds = BlockCodeVersion::where('block_id', $block->id)
            ->orderByDesc('id')
            ->limit(self::KEEP)
            ->pluck('id');

        BlockCodeVersion::where('block_id', $block->id)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
