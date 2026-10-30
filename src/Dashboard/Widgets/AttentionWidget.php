<?php

namespace Templite\Cms\Dashboard\Widgets;

use Illuminate\Support\Facades\Route;
use Templite\Cms\Contracts\DashboardWidget;
use Templite\Cms\Models\File;
use Templite\Cms\Models\Manager;
use Templite\Cms\Models\Page;

class AttentionWidget implements DashboardWidget
{
    /** Черновик старше — забытый */
    private const STALE_DRAFT_DAYS = 14;

    public function slug(): string
    {
        return 'cms.attention';
    }

    public function label(): string
    {
        return 'Требует внимания';
    }

    public function description(): string
    {
        return 'Страницы без SEO, картинки без alt, забытые черновики';
    }

    public function permission(): ?string
    {
        return 'pages.view';
    }

    public function view(): string
    {
        return self::VIEW_COUNTERS;
    }

    public function defaultWidth(): int
    {
        return 50;
    }

    public function data(Manager $manager, array $query = []): array
    {
        $pagesUrl = Route::has('cms.pages.index') ? route('cms.pages.index') : null;

        $items = [
            [
                // seo_data.title/description — ключи, которые реально попадают в <title>/<meta description>
                // (см. resources/views/components/meta-tags.blade.php), а не meta_title/meta_description
                'label' => 'Опубликованных страниц без SEO-заголовка или описания',
                'value' => Page::where('status', 1)
                    ->where(fn ($q) => $q
                        ->whereNull('seo_data->title')->orWhere('seo_data->title', '')
                        ->orWhereNull('seo_data->description')->orWhere('seo_data->description', ''))
                    ->count(),
                'url' => $pagesUrl,
                'tone' => 'warning',
            ],
            [
                'label' => 'Черновиков старше ' . self::STALE_DRAFT_DAYS . ' дней',
                'value' => Page::draft()
                    ->where('updated_at', '<', now()->subDays(self::STALE_DRAFT_DAYS))
                    // Черновик с будущей датой publish_at уже запланирован — не забыт
                    ->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
                    ->count(),
                'url' => $pagesUrl,
                'tone' => 'warning',
            ],
        ];

        if ($manager->hasPermission('media.view')) {
            $items[] = [
                'label' => 'Картинок без alt',
                'value' => File::images()->where(fn ($q) => $q->whereNull('alt')->orWhere('alt', ''))->count(),
                'url' => Route::has('cms.media.index') ? route('cms.media.index') : null,
                'tone' => 'warning',
            ];
        }

        return ['items' => $items];
    }
}
