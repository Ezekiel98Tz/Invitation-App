@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-semibold text-sm text-studio-text']) }}>
    {{ $value ?? $slot }}
</label>
