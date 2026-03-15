{{-- Компонент: <x-cms::image>
     Автоматический <picture> с AVIF -> WebP -> Original fallback.
     Параметры: file (File model), size (string|null), class (string), loading (lazy|eager) --}}

@props(['file', 'size' => null, 'class' => '', 'loading' => 'lazy'])

@if($file)
<picture>
    @if(method_exists($file, 'hasFormat') && $file->hasFormat($size, 'avif'))
        <source type="image/avif" srcset="{{ $file->url($size, 'avif') }}">
    @endif
    @if(method_exists($file, 'hasFormat') && $file->hasFormat($size, 'webp'))
        <source type="image/webp" srcset="{{ $file->url($size, 'webp') }}">
    @endif
    <img
        src="{{ $size ? $file->url($size) : $file->url }}"
        alt="{{ $file->alt ?? '' }}"
        @if($file->title) title="{{ $file->title }}" @endif
        loading="{{ $loading }}"
        @if(!empty($file->meta['width'])) width="{{ $file->meta['width'] }}" @endif
        @if(!empty($file->meta['height'])) height="{{ $file->meta['height'] }}" @endif
        @if(!empty($file->meta['dominant_color']))
            style="background: {{ $file->meta['dominant_color'] }}"
        @endif
        class="{{ $class }}"
    >
</picture>
@endif
