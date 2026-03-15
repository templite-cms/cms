{{-- Компонент: <x-cms::page-card>
     Карточка страницы для каталогов и списков.
     Параметры: page (Page model), showDate (bool), showTags (bool), variant (default|compact|horizontal) --}}

@props([
    'page',
    'showDate' => true,
    'showTags' => false,
    'variant' => 'default',
])

@if($page)
<article class="cms-page-card cms-page-card--{{ $variant }}">
    @if($page->image)
        <a href="{{ $page->full_url }}" class="cms-page-card__image-link">
            <x-cms::image :file="$page->image" size="medium" class="cms-page-card__image" />
        </a>
    @endif

    <div class="cms-page-card__content">
        @if($showDate && $page->created_at)
            <time class="cms-page-card__date" datetime="{{ $page->created_at->toIso8601String() }}">
                {{ $page->created_at->format('d.m.Y') }}
            </time>
        @endif

        <h3 class="cms-page-card__title">
            <a href="{{ $page->full_url }}" class="cms-page-card__title-link">
                {{ $page->title }}
            </a>
        </h3>

        @if($page->description)
            <p class="cms-page-card__description">{{ Str::limit($page->description, 150) }}</p>
        @endif

        @if($showTags && $page->pageType)
            <span class="cms-page-card__tag">{{ $page->pageType->name }}</span>
        @endif
    </div>
</article>
@endif
