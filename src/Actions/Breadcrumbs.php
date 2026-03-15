<?php

namespace Templite\Cms\Actions;

use Templite\Cms\Contracts\ActionContext;
use Templite\Cms\Contracts\BlockActionInterface;
use Templite\Cms\Services\BreadcrumbGenerator;

/**
 * Action: Хлебные крошки.
 *
 * Генерирует массив хлебных крошек для текущей страницы.
 * Применение: блок хлебных крошек на любой странице.
 */
class Breadcrumbs implements BlockActionInterface
{
    public function __construct(protected BreadcrumbGenerator $generator) {}

    public function params(): array
    {
        return [
            'include_home' => 'boolean',
            'home_title' => 'string',
            'include_current' => 'boolean',
        ];
    }

    public function returns(): array
    {
        return [
            'breadcrumbs' => 'array',
            'jsonLd' => 'string',
        ];
    }

    public function handle(array $params, ActionContext $context): array
    {
        $breadcrumbs = $this->generator->generate($context->page);

        // Добавить главную в начало
        $includeHome = $params['include_home'] ?? true;
        if ($includeHome) {
            $homeTitle = $params['home_title'] ?? 'Главная';
            array_unshift($breadcrumbs, [
                'title' => $homeTitle,
                'url' => '/',
            ]);
        }

        // Убрать ссылку с текущей страницы
        $includeCurrent = $params['include_current'] ?? true;
        if ($includeCurrent && !empty($breadcrumbs)) {
            $last = array_key_last($breadcrumbs);
            $breadcrumbs[$last]['url'] = null; // Текущая страница без ссылки
        }

        return [
            'breadcrumbs' => $breadcrumbs,
            'jsonLd' => $this->generator->generateJsonLd($breadcrumbs),
        ];
    }
}
