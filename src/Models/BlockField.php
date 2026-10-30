<?php

namespace Templite\Cms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;

class BlockField extends Model
{
    protected $fillable = [
        'name',
        'fieldable_type',
        'fieldable_id',
        'parent_id',
        'type',
        'key',
        'data',
        'hint',
        'order',
    ];

    protected $casts = [
        'data' => 'json',
    ];

    /**
     * Допустимые типы полей.
     */
    public const FIELD_TYPES = [
        'text', 'textfield', 'number', 'img', 'file', 'editor', 'tiptap', 'html',
        'select', 'checkbox', 'radio', 'link', 'date', 'datetime',
        'array', 'color', 'page',
        'category', 'product', 'product_option', // Shop module types
    ];

    /**
     * Зарезервированные ключи, которые нельзя использовать (BF-012).
     */
    public const RESERVED_KEYS = [
        'id', 'type', 'block', 'page', 'data', 'fields', 'global',
        'actions', 'request', 'slot', 'attributes', 'errors',
    ];

    // --- Relationships ---

    public function fieldable(): MorphTo
    {
        return $this->morphTo();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('order');
    }


    // --- Methods ---

    /**
     * Корневые поля с children на любую глубину — из плоского списка, без запросов.
     *
     * fields() владельца отдаёт все поля вперемешку с вложенными. Кто обходит
     * такой список как корневой, получает ключи дочерних полей на верхнем уровне.
     * Коллекция без вложенных полей считается уже корневой и возвращается как есть.
     */
    public static function tree(Collection $fields): Collection
    {
        if ($fields->every(fn (self $f) => $f->parent_id === null)) {
            return $fields->values();
        }

        $byParent = $fields->groupBy(fn (self $f) => $f->parent_id ?? 0);

        foreach ($fields as $field) {
            $field->setRelation('children', ($byParent[$field->id] ?? collect())->sortBy('order')->values());
        }

        return ($byParent[0] ?? collect())->sortBy('order')->values();
    }

    /**
     * Является ли поле повторителем (array).
     */
    public function isRepeater(): bool
    {
        return $this->type === 'array';
    }

    /**
     * Является ли поле файловым (img, file).
     */
    public function isFileField(): bool
    {
        return in_array($this->type, ['img', 'file']);
    }

    /**
     * Является ли поле ссылкой на другую сущность.
     */
    public function isReferenceField(): bool
    {
        return in_array($this->type, ['img', 'file', 'category', 'product', 'product_option']);
    }

    /**
     * Получить подсказку для контент-менеджера.
     * Поддерживает и поле hint, и data.hint для обратной совместимости.
     */
    public function getHintTextAttribute(): ?string
    {
        return $this->hint ?? ($this->data['hint'] ?? null);
    }
}
