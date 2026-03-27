<?php

namespace Templite\Cms\Contracts;

interface UserGuardInterface
{
    /**
     * Уникальное имя guard'а: "author", "customer".
     */
    public function getGuard(): string;

    /**
     * Человекочитаемое название: "Автор блога".
     */
    public function getLabel(): string;

    /**
     * Описание guard'а.
     */
    public function getDescription(): string;

    /**
     * Модуль-владелец: "blog", "shop".
     */
    public function getModule(): string;

    /**
     * Дефолтные поля для типа пользователя.
     * Формат: [['name' => '...', 'key' => '...', 'type' => '...', 'data' => [...], 'children' => [...]], ...]
     */
    public function getDefaultFields(): array;

    /**
     * Дефолтные разрешения для типа пользователя.
     */
    public function getDefaultPermissions(): array;

    /**
     * Дефолтные настройки для типа пользователя.
     */
    public function getDefaultSettings(): array;

    /**
     * Vite entry point для личного кабинета (или null).
     */
    public function getCabinetEntryPoint(): ?string;

    /**
     * Путь к файлу роутов личного кабинета (или null).
     */
    public function getCabinetRoutesFile(): ?string;

    /**
     * Middleware для личного кабинета.
     */
    public function getCabinetMiddleware(): array;
}
