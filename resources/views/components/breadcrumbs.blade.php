{{-- Компонент: <x-cms::breadcrumbs>
     Хлебные крошки с микроразметкой Schema.org.
     Параметры: items (array of {title, url}) --}}

@props(['items' => []])

@if(count($items) > 0)
<nav class="cms-breadcrumbs" aria-label="Хлебные крошки">
    <ol class="cms-breadcrumbs__list" itemscope itemtype="https://schema.org/BreadcrumbList">
        @foreach($items as $index => $item)
            <li class="cms-breadcrumbs__item" itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
                @if($item['url'] && !$loop->last)
                    <a href="{{ $item['url'] }}" class="cms-breadcrumbs__link" itemprop="item">
                        <span itemprop="name">{{ $item['title'] }}</span>
                    </a>
                @else
                    <span class="cms-breadcrumbs__current" itemprop="name">{{ $item['title'] }}</span>
                @endif
                <meta itemprop="position" content="{{ $index + 1 }}">
                @if(!$loop->last)
                    <span class="cms-breadcrumbs__separator" aria-hidden="true">/</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
@endif
