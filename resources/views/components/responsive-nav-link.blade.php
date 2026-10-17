@props(['active'])

@php
$classes = ($active ?? false)
            ? 'studio-responsive-nav-link studio-responsive-nav-link--active'
            : 'studio-responsive-nav-link';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }} @if($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
