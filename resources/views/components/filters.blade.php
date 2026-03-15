{{-- Компонент: <x-cms::filters>
     Фильтры для каталога страниц.
     Параметры: filters (array), active (array), class (string) --}}

@props(['filters' => [], 'active' => [], 'class' => ''])

@if(count($filters) > 0)
<div class="cms-filters {{ $class }}">
    <form class="cms-filters__form" method="GET" action="">
        @foreach($filters as $filter)
            @php
                $name = $filter['slug'] ?? $filter['name'] ?? '';
                $label = $filter['label'] ?? $filter['name'] ?? '';
                $type = $filter['type'] ?? 'select';
                $options = $filter['options'] ?? [];
                $currentValue = $active[$name] ?? request($name, '');
            @endphp

            <div class="cms-filters__group">
                <label class="cms-filters__label" for="filter_{{ $name }}">{{ $label }}</label>

                @if($type === 'select')
                    <select class="cms-filters__select"
                            id="filter_{{ $name }}"
                            name="{{ $name }}"
                            onchange="this.form.submit()">
                        <option value="">Все</option>
                        @foreach($options as $option)
                            <option value="{{ $option['value'] ?? $option }}"
                                    @if($currentValue == ($option['value'] ?? $option)) selected @endif>
                                {{ $option['label'] ?? $option }}
                            </option>
                        @endforeach
                    </select>
                @elseif($type === 'checkbox')
                    @foreach($options as $option)
                        <label class="cms-filters__checkbox">
                            <input type="checkbox"
                                   name="{{ $name }}[]"
                                   value="{{ $option['value'] ?? $option }}"
                                   @if(is_array($currentValue) && in_array($option['value'] ?? $option, $currentValue)) checked @endif
                                   onchange="this.form.submit()">
                            <span>{{ $option['label'] ?? $option }}</span>
                        </label>
                    @endforeach
                @endif
            </div>
        @endforeach

        @if(count($active) > 0)
            <a href="{{ url()->current() }}" class="cms-filters__reset">Сбросить</a>
        @endif
    </form>
</div>
@endif
