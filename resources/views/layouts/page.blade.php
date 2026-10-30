<!DOCTYPE html>
<html lang="{{ $lang ?? app()->getLocale() }}" class="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-cms::meta-tags :page="$page" />
    {!! $assets['libraries_css_html'] ?? '' !!}
    @if(!empty($assets['css']))
    <link rel="stylesheet" href="{{ $assets['css'] }}">
    @endif
    @stack('styles')
</head>
<body>
    @hasSection('content')
        @yield('content')
    @else
        {!! $__blocks_content ?? '' !!}
    @endif
    {!! $assets['libraries_js_html'] ?? '' !!}
    @if(!empty($assets['js']))
    <script src="{{ $assets['js'] }}"></script>
    @endif
    @stack('scripts')
</body>
</html>
