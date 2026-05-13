<x-guest-layout>
    <div class="text-center">
        <h1 class="text-xl font-semibold text-gray-900">Invite expired</h1>
        <p class="mt-2 text-sm text-gray-600">
            This invitation is no longer available.
        </p>
        <p class="mt-4 text-sm text-gray-600">
            {{ $guest->event->title }}
        </p>
    </div>
</x-guest-layout>
