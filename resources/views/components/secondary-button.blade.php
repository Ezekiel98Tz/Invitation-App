<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex min-h-11 items-center justify-center px-4 py-2 bg-studio-surface border border-studio-border rounded-md font-semibold text-sm text-studio-text hover:bg-studio-canvas focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-studio-focus focus-visible:ring-offset-2 disabled:bg-studio-disabled-surface disabled:text-studio-disabled disabled:cursor-not-allowed transition-colors duration-150']) }}>
    {{ $slot }}
</button>
