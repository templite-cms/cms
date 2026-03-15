<?php

namespace Templite\Cms\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Обёртка над массивом полей блока.
 *
 * Возвращает null вместо ошибки при обращении к несуществующему ключу.
 * Вложенные массивы автоматически оборачиваются в FieldsBag.
 * Поддерживает foreach, count и доступ через $fields['key'].
 */
class FieldsBag implements ArrayAccess, IteratorAggregate, Countable
{
    public function __construct(protected array $data = []) {}

    public static function wrap(array $data): static
    {
        return new static($data);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!array_key_exists($offset, $this->data)) {
            // Пустой FieldsBag: {{ $fields['x'] }} → '', @foreach($fields['x']) → 0 итераций
            return new static([]);
        }

        $value = $this->data[$offset];

        // Вложенные массивы оборачиваем рекурсивно
        if (is_array($value)) {
            return new static($value);
        }

        return $value;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function getIterator(): Traversable
    {
        // Оборачиваем вложенные массивы при итерации
        $wrapped = [];
        foreach ($this->data as $key => $value) {
            $wrapped[$key] = is_array($value) ? new static($value) : $value;
        }

        return new ArrayIterator($wrapped);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Позволяет использовать в строковом контексте ({{ $fields['missing'] }}).
     */
    public function __toString(): string
    {
        return '';
    }
}
