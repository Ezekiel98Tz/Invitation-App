<x-guest-layout>
    <div class="space-y-4 text-center">
        <h1 class="text-xl font-semibold text-gray-900">Invite expired</h1>
        <p class="mt-2 text-sm text-gray-600">
            This invitation is no longer available.
        </p>
        <p class="text-sm text-gray-600">
            {{ $guest->event?->title ?? 'This event is no longer available.' }}
        </p>
        <a
            href="{{ url('/e/'.$guest->invite_token.'/pass') }}"
            class="inline-flex items-center justify-center rounded-md border border-transparent bg-studio-accent px-4 py-2 text-sm font-medium text-white hover:bg-studio-accent-hover"
        >
            View event pass
        </a>
    </div>
</x-guest-layout>
