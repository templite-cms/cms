{{-- Компонент: <x-cms::image>
     Автоматический <picture> с AVIF -> WebP -> Original fallback.
     Принимает file (модель File ЛИБО id файла) или id (id файла).
     Это позволяет выводить и блочные img-поля (модель File),
     и глобальные/шаблонные img-поля (хранят id).
     Параметры: file, id, size (string|null), class (string), loading (lazy|eager) --}}

@props(['file' => null, 'id' => null, 'size' => null, 'class' => '', 'loading' => 'lazy'])

@php($file = $file instanceof \Templite\Cms\Models\File ? $file : cms_file($id ?? $file))

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
