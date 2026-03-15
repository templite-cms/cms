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

    public function cdnCssLinks(): array
    {
        return collect($this->cdn_links ?? [])->where('type', 'css')->pluck('url')->all();
    }

    public function cdnJsLinks(): array
    {
        return collect($this->cdn_links ?? [])->where('type', 'js')->pluck('url')->all();
    }
}
