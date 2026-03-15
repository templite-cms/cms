<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('cms.name', 'Templite CMS') }}</title>
    <link rel="icon" href="/vendor/cms/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/vendor/cms/favicon.ico" sizes="32x32">
    <link rel="icon" href="/vendor/cms/favicon-96x96.png" sizes="96x96" type="image/png">
    <link rel="apple-touch-icon" href="/vendor/cms/apple-touch-icon.png">
    <link rel="manifest" href="/vendor/cms/site.webmanifest">

    {{-- Module styles --}}
    @foreach(app(\Templite\Cms\Services\ModuleRegistry::class)->getStyles() as $style)
        <link rel="stylesheet" href="{{ asset($style) }}">
    @endforeach

    {{-- Shared vendor globals for module IIFE bundles --}}
    @if(app(\Templite\Cms\Services\ModuleRegistry::class)->getScripts())
        @if(file_exists(public_path('vendor/cms/js/vendor-globals.js')))
            <script src="{{ asset('vendor/cms/js/vendor-globals.js') }}"></script>
        @endif
    @endif

    {{-- Module scripts (IIFE — register pages/widgets in window.__CMS_PAGES / __CMS_WIDGETS) --}}
    @foreach(app(\Templite\Cms\Services\ModuleRegistry::class)->getScripts() as $script)
        <script src="{{ asset($script) }}"></script>
    @endforeach

    {{-- CMS core (Vite ES module) --}}
    @vite(['packages/templite/cms/resources/js/app.js', 'packages/templite/cms/resources/css/app.css'])
    @inertiaHead
</head>
<body class="font-sans antialiased">
    @inertia
</body>
</html>
