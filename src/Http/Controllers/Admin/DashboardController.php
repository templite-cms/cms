<?php

namespace Templite\Cms\Http\Controllers\Admin;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Templite\Cms\Http\CmsResponse;
use Templite\Cms\Models\Page;
use Templite\Cms\Models\Block;
use Templite\Cms\Models\TemplatePage;
use Templite\Cms\Models\Component;
use Templite\Cms\Models\PageType;
use Templite\Cms\Models\BlockType;

class DashboardController extends Controller
{
    private const DEFAULT_LAYOUT = [
        ['type' => 'recent_pages', 'width' => 100, 'order' => 0],
        ['type' => 'quick_actions', 'width' => 50, 'order' => 1],
    ];

    private const PRESETS = [
        'content_manager' => [
            ['type' => 'recent_pages', 'width' => 100, 'order' => 0],
            ['type' => 'quick_actions', 'width' => 50, 'order' => 1],
        ],
        'admin' => [
            ['type' => 'recent_pages', 'width' => 100, 'order' => 0],
            ['type' => 'quick_actions', 'width' => 50, 'order' => 1],
            ['type' => 'content_stats', 'width' => 50, 'order' => 2],
        ],
        'developer' => [
            ['type' => 'quick_actions', 'width' => 50, 'order' => 0],
            ['type' => 'content_stats', 'width' => 50, 'order' => 1],
        ],
    ];

    public function index(): Response
    {
        $manager = auth('manager')->user();
        $settings = $manager->settings ?? [];
        $layout = $settings['dashboard']['widgets'] ?? self::DEFAULT_LAYOUT;
        $preset = $settings['dashboard']['preset'] ?? null;

        $activeTypes = array_column($layout, 'type');

        $widgetData = [];

        if (in_array('recent_pages', $activeTypes)) {
            $widgetData['recent_pages'] = Page::with(['pageType', 'screenshot'])
                ->orderByDesc('updated_at')
                ->limit(9)
                ->get()
                ->map(fn ($page) => [
                    'id' => $page->id,
                    'title' => $page->title,
                    'url' => $page->full_url,
                    'status' => $page->status,
                    'is_published' => $page->is_published,
                    'page_type' => $page->pageType ? ['name' => $page->pageType->name] : null,
                    'screenshot_url' => $page->screenshot?->url(),
                    'updated_at' => $page->updated_at?->toISOString(),
                    'edit_url' => '/' . \Templite\Cms\Models\CmsConfig::getAdminUrl() . '/pages/' . $page->id . '/edit',
                ]);
        }

        // Data for quick actions modals
        if (in_array('quick_actions', $activeTypes)) {
            $widgetData['page_types'] = PageType::select('id', 'name')->orderBy('name')->get();
            $widgetData['all_pages'] = Page::select('id', 'title')->orderBy('title')->get();
            $widgetData['block_types'] = BlockType::select('id', 'name')->orderBy('name')->get();
        }

        if (in_array('content_stats', $activeTypes)) {
            $widgetData['content_stats'] = [
                'pages' => Page::count(),
                'blocks' => Block::count(),
                'templates' => TemplatePage::count(),
                'components' => Component::count(),
            ];
        }

        return CmsResponse::page('packages/templite/cms/resources/js/entries/dashboard.js', [
            'layout' => $layout,
            'preset' => $preset,
            'presets' => array_keys(self::PRESETS),
            'widgetData' => $widgetData,
        ], ['title' => 'Дашборд']);
    }
}
