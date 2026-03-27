{{-- Компонент: <x-cms::tiptap>
     Рендерит HTML из tiptap-поля с автоматической обработкой изображений.
     Изображения с data-file-id заменяются на <x-cms::image> (picture + webp/avif).
     Параметры: content (string), class (string) --}}

@props(['content' => '', 'class' => ''])

@if($content)
<div @class(['tiptap-content', $class])>
    {!! app(\Templite\Cms\Services\TiptapHtmlProcessor::class)->process($content) !!}
</div>
@endif
