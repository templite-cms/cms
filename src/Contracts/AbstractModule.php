<?php

namespace Templite\Cms\Contracts;

abstract class AbstractModule implements TempliteModuleInterface
{
    abstract public function getName(): string;
    abstract public function getLabel(): string;

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getNavigation(): array
    {
        return [];
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getDashboardWidgets(): array
    {
        return [];
    }

    public function getSettings(): array
    {
        return [];
    }

    public function getScripts(): array
    {
        return [];
    }

    public function getStyles(): array
    {
        return [];
    }
}
