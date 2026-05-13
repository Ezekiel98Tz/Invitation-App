@php
    $manifestPath = public_path('build/manifest.json');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
    $css = $manifest['resources/css/app.css']['file'] ?? null;
    $js = $manifest['resources/js/app.js']['file'] ?? null;
@endphp

@if($css)
    <link rel="stylesheet" href="{{ asset('build/'.$css) }}">
@endif

@if($js)
    <script type="module" src="{{ asset('build/'.$js) }}"></script>
@endif
