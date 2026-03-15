{{-- Компонент: <x-cms::pagination>
     Пагинация с навигацией.
     Параметры: paginator (LengthAwarePaginator) --}}

@props(['paginator'])

@if($paginator && $paginator->hasPages())
<nav class="cms-pagination" aria-label="Навигация по страницам">
    <ul class="cms-pagination__list">
        {{-- Предыдущая --}}
        @if($paginator->onFirstPage())
            <li class="cms-pagination__item cms-pagination__item--disabled">
                <span class="cms-pagination__link">&laquo; Назад</span>
            </li>
        @else
            <li class="cms-pagination__item">
                <a href="{{ $paginator->previousPageUrl() }}" class="cms-pagination__link" rel="prev">&laquo; Назад</a>
            </li>
        @endif

        {{-- Номера страниц --}}
        @foreach($paginator->getUrlRange(1, $paginator->lastPage()) as $page => $url)
            @if($page == $paginator->currentPage())
                <li class="cms-pagination__item cms-pagination__item--active">
                    <span class="cms-pagination__link">{{ $page }}</span>
                </li>
            @else
                <li class="cms-pagination__item">
                    <a href="{{ $url }}" class="cms-pagination__link">{{ $page }}</a>
                </li>
            @endif
        @endforeach

        {{-- Следующая --}}
        @if($paginator->hasMorePages())
            <li class="cms-pagination__item">
                <a href="{{ $paginator->nextPageUrl() }}" class="cms-pagination__link" rel="next">Вперёд &raquo;</a>
            </li>
        @else
            <li class="cms-pagination__item cms-pagination__item--disabled">
                <span class="cms-pagination__link">Вперёд &raquo;</span>
            </li>
        @endif
    </ul>
</nav>
@endif
