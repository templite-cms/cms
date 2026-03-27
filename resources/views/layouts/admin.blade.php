<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ? "$pageTitle — Templite CMS" : 'Templite CMS' }}</title>

    <link rel="icon" href="/vendor/cms/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/vendor/cms/favicon.ico" sizes="32x32">
    <link rel="icon" href="/vendor/cms/favicon-96x96.png" sizes="96x96" type="image/png">
    <link rel="apple-touch-icon" href="/vendor/cms/apple-touch-icon.png">

    @vite('packages/templite/cms/resources/scss/app.scss')
    @foreach(($pageAssets ?? []) as $asset)
        @if(str_ends_with($asset, '.css'))
            <link rel="stylesheet" href="{{ $asset }}">
        @endif
    @endforeach
</head>
<body class="admin-layout">
    {{-- Header — Vue island --}}
    <div id="header-island"></div>
    <script type="application/json" id="header-props">
        {!! json_encode(['navigation' => $navigation, 'user' => $user, 'currentUrl' => $currentUrl, 'cmsConfig' => $cmsConfig], JSON_UNESCAPED_UNICODE) !!}
    </script>

    {{-- Content — Vue page --}}
    <main class="admin-layout__content">
        <div id="page"></div>
    </main>
    <script type="application/json" id="page-props">
        @json($pageProps)
    </script>

    @if($pageEntry)
        @vite(['packages/templite/cms/resources/js/islands/header.js', $pageEntry])
    @else
        @vite('packages/templite/cms/resources/js/islands/header.js')
    @endif
    @foreach(($pageAssets ?? []) as $asset)
        @if(str_ends_with($asset, '.js'))
            <script type="module" src="{{ $asset }}"></script>
        @endif
    @endforeach
</body>
</html>
