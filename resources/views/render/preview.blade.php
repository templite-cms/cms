<!DOCTYPE html>
<html class="light">
<head>
    <base href="/">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {!! $cdnCss ?? '' !!}
    <style>body{margin:0;padding:0;overflow:hidden} {!! $css ?? '' !!}</style>
</head>
<body>
    {!! $content !!}
    {!! $cdnJs ?? '' !!}
    @if(!empty($js))
        <script>{!! $js !!}</script>
    @endif
    @if(!empty($postScript))
        <script>{!! $postScript !!}</script>
    @endif
</body>
</html>
