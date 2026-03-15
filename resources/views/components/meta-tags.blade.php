{{-- Компонент: <x-cms::meta-tags>
     SEO-метатеги и Open Graph разметка.
     Параметры: page (Page model) --}}

@props(['page'])

@if($page)
@php
    $seo = $page->seo_data ?? [];
    $social = $page->social_data ?? [];
    $title = $seo['title'] ?? $page->title;
    $description = $seo['description'] ?? $page->description ?? '';
    $keywords = $seo['keywords'] ?? '';
    $canonicalUrl = $seo['canonical_url'] ?? url($page->full_url);
    $robots = $seo['robots'] ?? 'index, follow';
    $ogTitle = $social['og_title'] ?? $title;
    $ogDescription = $social['og_description'] ?? $description;
    $ogImage = $social['og_image'] ?? ($page->image ? $page->image->url : '');
    $siteName = $global['site_name'] ?? config('app.name', 'Templite');
@endphp

{{-- Базовые метатеги --}}
<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
@if($keywords)
<meta name="keywords" content="{{ $keywords }}">
@endif
<meta name="robots" content="{{ $robots }}">
<link rel="canonical" href="{{ $canonicalUrl }}">

{{-- Open Graph --}}
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $ogTitle }}">
<meta property="og:description" content="{{ $ogDescription }}">
<meta property="og:url" content="{{ $canonicalUrl }}">
<meta property="og:site_name" content="{{ $siteName }}">
@if($ogImage)
<meta property="og:image" content="{{ $ogImage }}">
@endif

{{-- Twitter Card --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $ogTitle }}">
<meta name="twitter:description" content="{{ $ogDescription }}">
@if($ogImage)
<meta name="twitter:image" content="{{ $ogImage }}">
@endif
@endif
