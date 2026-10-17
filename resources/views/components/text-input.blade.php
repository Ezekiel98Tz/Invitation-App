@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'min-h-11 bg-studio-surface text-studio-text placeholder:text-studio-muted border-studio-border focus:border-studio-accent focus:ring-studio-accent disabled:bg-studio-disabled-surface disabled:text-studio-disabled rounded-md shadow-sm']) }}>
