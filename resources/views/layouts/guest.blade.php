<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Templite CMS</title>

    <link rel="icon" href="/vendor/cms/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/vendor/cms/favicon.ico" sizes="32x32">

    @vite('packages/templite/cms/resources/scss/app.scss')
</head>
<body>
    <div id="page"></div>
    <script type="application/json" id="page-props">
        @json($pageProps)
    </script>

    @vite($pageEntry)
</body>
</html>
