<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LibraryFile extends Model
{
    /** Тип файла: чем он подключается на странице. */
    public const TYPES = ['js', 'css'];

    /** Откуда берётся файл: загружен в CMS или лежит на стороннем CDN. */
    public const SOURCES = ['local', 'cdn'];

    /** Способ загрузки js-файла. Пустое значение — обычный синхронный script. */
    public const LOADINGS = ['defer', 'async', 'module'];

    protected $fillable = [
        'library_id',
        'type',
        'source',
        'path',
        'url',
        'original_name',
        'version',
        'loading',
        'media',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    // --- Relationships ---

    public function library(): BelongsTo
    {
        return $this->belongsTo(Library::class);
    }

    // --- Scopes ---

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    // --- Helpers ---

    /**
     * Финальный URL файла. Единственное место, где он вычисляется.
     * Возвращает пустую строку, если файл ещё не привязан (нет path/url).
     *
     * У CDN-ссылки версия — справочная метка для редактора: в самом URL она
     * уже зашита, поэтому ?v= к нему не дописывается.
     */
    public function resolvedUrl(): string
    {
        if ($this->source === 'cdn') {
            return (string) ($this->url ?? '');
        }

        if (blank($this->path)) {
            return '';
        }

        // Имя файла приходит из загрузки или из ZIP-архива и может содержать
        // пробелы и решётки — без кодирования получился бы битый href.
        $encoded = implode('/', array_map('rawurlencode', explode('/', ltrim($this->path, '/'))));

        $url = asset('storage/' . $encoded);

        if (filled($this->version)) {
            $url .= '?v=' . rawurlencode($this->version);
        }

        return $url;
    }
}
