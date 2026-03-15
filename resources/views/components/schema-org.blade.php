{{-- Компонент: <x-cms::schema-org>
     Schema.org JSON-LD разметка.
     Параметры: page (Page model), type (string: WebPage, Article, Organization) --}}

@props(['page', 'type' => 'WebPage'])

@if($page)
@php
    $seo = $page->seo_data ?? [];
    $siteName = $global['site_name'] ?? config('app.name', 'Templite');
    $siteUrl = config('app.url', url('/'));

    $schema = [
        '@context' => 'https://schema.org',
        '@type' => $type,
        'name' => $seo['title'] ?? $page->title,
        'description' => $seo['description'] ?? $page->description ?? '',
        'url' => url($page->full_url),
    ];

    if ($page->created_at) {
        $schema['datePublished'] = $page->created_at->toIso8601String();
    }
    if ($page->updated_at) {
        $schema['dateModified'] = $page->updated_at->toIso8601String();
    }

    if ($page->image) {
        $schema['image'] = $page->image->url;
    }

    // Организация (для всех типов страниц)
    $schema['publisher'] = [
        '@type' => 'Organization',
        'name' => $siteName,
        'url' => $siteUrl,
    ];

    if (!empty($global['logo'])) {
        $schema['publisher']['logo'] = [
            '@type' => 'ImageObject',
            'url' => $global['logo'],
        ];
    }
@endphp

<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}</script>
@endif
