<?php

namespace Templite\Cms\Services;

use Illuminate\Contracts\Foundation\Application;
use Templite\Cms\Contracts\PageHandlerInterface;

class HandlerRegistry
{
    protected array $handlers = [];

    public function __construct(protected Application $app)
    {
    }

    /**
     * Зарегистрировать handler.
     */
    public function register(string $name, string $handlerClass): void
    {
        $this->handlers[$name] = $handlerClass;
    }

    /**
     * Проверить, зарегистрирован ли handler.
     */
    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * Получить экземпляр handler'а.
     */
    public function resolve(string $name): PageHandlerInterface
    {
        if (!$this->has($name)) {
            throw new \InvalidArgumentException("Handler '{$name}' is not registered.");
        }

        return $this->app->make($this->handlers[$name]);
    }

    /**
     * Получить все зарегистрированные handler'ы (для UI выбора в админке).
     *
     * @return array<string, string> [name => label]
     */
    public function all(): array
    {
        $result = [];

        foreach ($this->handlers as $name => $class) {
            $handler = $this->app->make($class);
            $result[$name] = $handler->getLabel();
        }

        return $result;
    }
}
