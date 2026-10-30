<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-cms::meta-tags :page="$page" />
    {!! $assets['libraries_css_html'] ?? '' !!}
    @if(!empty($assets['css']))
        <link rel="stylesheet" href="{{ $assets['css'] }}">
    @endif
</head>
<body>
    @yield('blocks')

    {!! $assets['libraries_js_html'] ?? '' !!}
    @if(!empty($assets['js']))
        <script src="{{ $assets['js'] }}"></script>
    @endif
</body>
</html>
