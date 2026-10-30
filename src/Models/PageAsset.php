<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageAsset extends Model
{
    protected $fillable = [
        'page_id',
        'css_path',
        'js_path',
        'cdn_links',
        'hash',
    ];

    protected $casts = [
        'cdn_links' => 'json',
    ];

    // --- Relationships ---

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    // --- Helpers ---

    public function cssUrl(): ?string
    {
        return $this->css_path ? asset('storage/' . ltrim($this->css_path, '/')) : null;
    }

    public function jsUrl(): ?string
    {
        return $this->js_path ? asset('storage/' . ltrim($this->js_path, '/')) : null;
    }

    /**
     * @return array<int, array{type: string, url: string, loading: ?string, media: ?string}>
     */
    public function cdnCssLinks(): array
    {
        return $this->normalizedLinks('css');
    }

    /**
     * @return array<int, array{type: string, url: string, loading: ?string, media: ?string}>
     */
    public function cdnJsLinks(): array
    {
        return $this->normalizedLinks('js');
    }

    /**
     * Отбирает из cdn_links подключения нужного типа.
     *
     * Содержимое колонки — непроверенный JSON: он мог быть записан более
     * старой версией компилятора или повреждён вручную. Элементы неизвестной
     * или неполной формы пропускаются, чтобы страница не падала и не получала
     * тег с пустым href. Правило то же, что в LibraryAssetBuilder::collect():
     * подключение без пригодного URL не выводится.
     */
    protected function normalizedLinks(string $type): array
    {
        $links = [];

        foreach ($this->cdn_links ?? [] as $link) {
            if (!is_array($link) || ($link['type'] ?? null) !== $type) {
                continue;
            }

            $url = $link['url'] ?? null;

            if (!is_string($url) || $url === '') {
                continue;
            }

            $links[] = [
                'type' => $type,
                'url' => $url,
                'loading' => $link['loading'] ?? null,
                'media' => $link['media'] ?? null,
            ];
        }

        return $links;
    }
}
