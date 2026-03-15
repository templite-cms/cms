<!DOCTYPE html>
<html lang="{{ $lang ?? str_replace('_', '-', app()->getLocale()) }}" class="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-cms::meta-tags :page="$page" />
    @if(!empty($hreflang))
    {!! $hreflang !!}
    @endif
    @foreach($assets['cdn_css'] ?? [] as $css)
    <link rel="stylesheet" href="{{ $css }}">
    @endforeach
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
    @foreach($assets['cdn_js'] ?? [] as $js)
    <script src="{{ $js }}"></script>
    @endforeach
    @if(!empty($assets['js']))
    <script src="{{ $assets['js'] }}"></script>
    @endif
    @stack('scripts')
</body>
</html>
