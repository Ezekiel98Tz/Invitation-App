<x-guest-layout>
    <div class="space-y-4 text-center">
        <h1 class="text-xl font-semibold text-gray-900">{{ $guest->event->title }}</h1>
        <p class="text-sm text-gray-600">Thanks, {{ $guest->name }}.</p>
        <div class="rounded-md bg-green-50 p-3 text-sm text-green-700">
            Your RSVP has been recorded.
        </div>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <a
                href="{{ route('invites.show', ['token' => $guest->invite_token], absolute: false) }}"
                class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50"
            >
                View / Update RSVP
            </a>
            @if($guest->status === 'accepted')
                <a
                    href="{{ url('/e/'.$guest->invite_token.'/pass') }}"
                    class="inline-flex items-center justify-center rounded-md border border-transparent bg-studio-accent px-4 py-2 text-sm font-medium text-white hover:bg-studio-accent-hover"
                >
                    View event pass
                </a>
            @endif
        </div>
    </div>
</x-guest-layout>
