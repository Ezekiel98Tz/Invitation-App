<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex min-h-11 items-center justify-center px-4 py-2 bg-studio-danger border border-transparent rounded-md font-semibold text-sm text-white hover:bg-studio-danger-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-studio-danger focus-visible:ring-offset-2 disabled:bg-studio-disabled-surface disabled:text-studio-disabled disabled:cursor-not-allowed transition-colors duration-150']) }}>
    {{ $slot }}
</button>
