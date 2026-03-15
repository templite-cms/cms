{{--
    TASK-S01: Honeypot anti-bot компонент.

    Использование в Blade-шаблонах:
        <x-cms::honeypot />

    Добавляет скрытое поле-ловушку и timestamp создания формы.
    Боты, автоматически заполняющие все поля, заполнят honeypot и будут заблокированы.
--}}
@php
    $fieldName = config('cms.honeypot.field', '_hp_name');
    $timeField = config('cms.honeypot.time_field', '_hp_time');
@endphp

<div style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;width:0;overflow:hidden;" aria-hidden="true" tabindex="-1">
    <label for="{{ $fieldName }}">Leave this empty</label>
    <input type="text" name="{{ $fieldName }}" id="{{ $fieldName }}" value="" autocomplete="off" tabindex="-1">
</div>
<input type="hidden" name="{{ $timeField }}" value="{{ time() }}">
