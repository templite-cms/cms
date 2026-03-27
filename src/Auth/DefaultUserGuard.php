<?php

namespace Templite\Cms\Auth;

use Templite\Cms\Contracts\UserGuardInterface;

class DefaultUserGuard implements UserGuardInterface
{
    public function getGuard(): string
    {
        return 'user';
    }

    public function getLabel(): string
    {
        return 'Пользователь';
    }

    public function getDescription(): string
    {
        return 'Пользователь сайта';
    }

    public function getModule(): string
    {
        return 'cms';
    }

    public function getDefaultFields(): array
    {
        return [];
    }

    public function getDefaultPermissions(): array
    {
        return [];
    }

    public function getDefaultSettings(): array
    {
        return [];
    }

    public function getCabinetEntryPoint(): ?string
    {
        return null;
    }

    public function getCabinetRoutesFile(): ?string
    {
        return null;
    }

    public function getCabinetMiddleware(): array
    {
        return [];
    }
}
