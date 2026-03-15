{{-- Компонент: <x-cms::lead-form>
     Универсальная форма заявки (лид).
     Параметры: form (LeadForm model или массив с полями) --}}

@props(['form', 'pageBlockId' => null, 'blockSlug' => null, 'class' => ''])

@php
    $fields = is_array($form) ? $form['fields'] ?? [] : ($form->fields ?? []);
    $formName = is_array($form) ? $form['name'] ?? 'form' : ($form->name ?? 'form');
    $formHash = is_array($form) ? $form['hash'] ?? '' : ($form->hash ?? '');
    $submitUrl = $pageBlockId
        ? route('cms.block-action', $pageBlockId)
        : ($blockSlug ? route('cms.action', $blockSlug) : '#');
    $submitText = is_array($form) ? ($form['settings']['submit_text'] ?? 'Отправить') : ($form->settings['submit_text'] ?? 'Отправить');
@endphp

<form class="cms-lead-form {{ $class }}"
      action="{{ $submitUrl }}"
      method="POST"
      data-cms-form="{{ $formName }}"
      data-form-hash="{{ $formHash }}">

    @csrf
    <x-cms::honeypot />

    <input type="hidden" name="_form_hash" value="{{ $formHash }}">
    @if($pageBlockId)
        <input type="hidden" name="_page_block_id" value="{{ $pageBlockId }}">
    @endif

    @foreach($fields as $field)
        @php
            $name = $field['name'] ?? $field['key'] ?? 'field_' . $loop->index;
            $label = $field['label'] ?? $name;
            $type = $field['type'] ?? 'text';
            $required = $field['required'] ?? false;
            $placeholder = $field['placeholder'] ?? '';
        @endphp

        <div class="cms-lead-form__field">
            <label class="cms-lead-form__label" for="{{ $formName }}_{{ $name }}">
                {{ $label }}
                @if($required)<span class="cms-lead-form__required">*</span>@endif
            </label>

            @if($type === 'textarea')
                <textarea
                    class="cms-lead-form__input cms-lead-form__textarea"
                    id="{{ $formName }}_{{ $name }}"
                    name="{{ $name }}"
                    placeholder="{{ $placeholder }}"
                    rows="4"
                    @if($required) required @endif
                ></textarea>
            @elseif($type === 'select')
                <select
                    class="cms-lead-form__input cms-lead-form__select"
                    id="{{ $formName }}_{{ $name }}"
                    name="{{ $name }}"
                    @if($required) required @endif
                >
                    <option value="">{{ $placeholder ?: '-- Выберите --' }}</option>
                    @foreach($field['options'] ?? [] as $option)
                        <option value="{{ $option['value'] ?? $option }}">{{ $option['label'] ?? $option }}</option>
                    @endforeach
                </select>
            @elseif($type === 'checkbox')
                <label class="cms-lead-form__checkbox-label">
                    <input type="checkbox"
                           class="cms-lead-form__checkbox"
                           id="{{ $formName }}_{{ $name }}"
                           name="{{ $name }}"
                           value="1"
                           @if($required) required @endif
                    >
                    <span>{{ $placeholder }}</span>
                </label>
            @else
                <input
                    type="{{ $type }}"
                    class="cms-lead-form__input"
                    id="{{ $formName }}_{{ $name }}"
                    name="{{ $name }}"
                    placeholder="{{ $placeholder }}"
                    @if($required) required @endif
                >
            @endif

            <div class="cms-lead-form__error" data-error="{{ $name }}"></div>
        </div>
    @endforeach

    <div class="cms-lead-form__submit">
        <button type="submit" class="cms-lead-form__button">{{ $submitText }}</button>
    </div>

    <div class="cms-lead-form__message" style="display:none"></div>
</form>
