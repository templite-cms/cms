<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-cms::meta-tags :page="$page" />
    @foreach($assets['cdn_css'] ?? [] as $css)
        <link rel="stylesheet" href="{{ $css }}">
    @endforeach
    @if(!empty($assets['css']))
        <link rel="stylesheet" href="{{ $assets['css'] }}">
    @endif
</head>
<body>
    @yield('blocks')

    @foreach($assets['cdn_js'] ?? [] as $js)
        <script src="{{ $js }}"></script>
    @endforeach
    @if(!empty($assets['js']))
        <script src="{{ $assets['js'] }}"></script>
    @endif
</body>
</html>
