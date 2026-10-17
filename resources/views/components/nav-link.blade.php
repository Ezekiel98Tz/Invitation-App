@props(['active'])

@php
$classes = ($active ?? false)
            ? 'studio-nav-link studio-nav-link--active'
            : 'studio-nav-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} @if($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
