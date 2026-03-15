{{-- Компонент: <x-cms::share-buttons>
     Кнопки для шеринга в соцсети.
     Параметры: url (string), title (string) --}}

@props(['url', 'title' => ''])

@php
    $encodedUrl = urlencode($url);
    $encodedTitle = urlencode($title);
@endphp

<div class="cms-share-buttons">
    <span class="cms-share-buttons__label">Поделиться:</span>

    {{-- VK --}}
    <a href="https://vk.com/share.php?url={{ $encodedUrl }}&title={{ $encodedTitle }}"
       class="cms-share-buttons__link cms-share-buttons__link--vk"
       target="_blank" rel="noopener noreferrer" aria-label="Поделиться в VK">
        VK
    </a>

    {{-- Telegram --}}
    <a href="https://t.me/share/url?url={{ $encodedUrl }}&text={{ $encodedTitle }}"
       class="cms-share-buttons__link cms-share-buttons__link--telegram"
       target="_blank" rel="noopener noreferrer" aria-label="Поделиться в Telegram">
        TG
    </a>

    {{-- WhatsApp --}}
    <a href="https://api.whatsapp.com/send?text={{ $encodedTitle }}%20{{ $encodedUrl }}"
       class="cms-share-buttons__link cms-share-buttons__link--whatsapp"
       target="_blank" rel="noopener noreferrer" aria-label="Поделиться в WhatsApp">
        WA
    </a>

    {{-- Twitter/X --}}
    <a href="https://twitter.com/intent/tweet?url={{ $encodedUrl }}&text={{ $encodedTitle }}"
       class="cms-share-buttons__link cms-share-buttons__link--twitter"
       target="_blank" rel="noopener noreferrer" aria-label="Поделиться в Twitter">
        X
    </a>

    {{-- Копировать ссылку --}}
    <button class="cms-share-buttons__link cms-share-buttons__link--copy"
            onclick="navigator.clipboard.writeText('{{ $url }}').then(() => this.textContent = 'Скопировано!')"
            aria-label="Скопировать ссылку">
        Ссылка
    </button>
</div>
