<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Invitation & RSVP') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    @include('partials.assets')
</head>
<body class="font-sans antialiased bg-gray-50 text-gray-900">
<div class="min-h-screen flex flex-col">
    <header class="border-b border-gray-200 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <x-application-logo class="h-8 w-8 fill-current text-gray-900" />
                <div class="font-semibold">{{ config('app.name', 'Invitation & RSVP') }}</div>
            </div>

            <div class="flex items-center gap-2">
                @auth
                    <a href="{{ route('dashboard', absolute: false) }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">Dashboard</a>
                @else
                    <a href="{{ route('login', absolute: false) }}" class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">Log in</a>
                    <a href="{{ route('register', absolute: false) }}" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">Register</a>
                @endauth
            </div>
        </div>
    </header>

    <main class="flex-1">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-14">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 items-center">
                <div class="space-y-6">
                    <div class="inline-flex items-center rounded-full bg-indigo-50 px-3 py-1 text-sm font-medium text-indigo-700">
                        Invitation & RSVP Platform
                    </div>

                    <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-gray-900">
                        Create events, invite guests, and track RSVPs in one place.
                    </h1>

                    <p class="text-gray-600">
                        Import guest lists, send invitations, collect RSVPs with custom questions, and track delivery and responses from the dashboard.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        @auth
                            <a href="{{ route('dashboard', absolute: false) }}" class="rounded-md bg-indigo-600 px-5 py-3 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">Go to dashboard</a>
                        @else
                            <a href="{{ route('login', absolute: false) }}" class="rounded-md bg-indigo-600 px-5 py-3 text-sm font-medium text-white shadow-sm hover:bg-indigo-700">Log in</a>
                            <a href="{{ route('register', absolute: false) }}" class="rounded-md border border-gray-300 bg-white px-5 py-3 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50">Create account</a>
                        @endauth
                    </div>
                </div>

                <div class="bg-white shadow-sm rounded-lg border border-gray-200 p-6">
                    <div class="text-sm font-medium text-gray-900">Preview checklist</div>
                    <ul class="mt-4 space-y-3 text-sm text-gray-700">
                        <li class="flex gap-2"><span class="mt-0.5 h-2 w-2 rounded-full bg-indigo-600"></span><span>Log in and open the dashboard</span></li>
                        <li class="flex gap-2"><span class="mt-0.5 h-2 w-2 rounded-full bg-indigo-600"></span><span>Create an event</span></li>
                        <li class="flex gap-2"><span class="mt-0.5 h-2 w-2 rounded-full bg-indigo-600"></span><span>Import guests (mapping wizard)</span></li>
                        <li class="flex gap-2"><span class="mt-0.5 h-2 w-2 rounded-full bg-indigo-600"></span><span>Send invitations</span></li>
                        <li class="flex gap-2"><span class="mt-0.5 h-2 w-2 rounded-full bg-indigo-600"></span><span>Open /invite/{token} and RSVP</span></li>
                    </ul>
                    <div class="mt-6 rounded-md bg-gray-50 p-4 text-sm text-gray-700">
                        Guest invites look like:
                        <div class="mt-2 font-mono text-xs text-gray-600">/invite/{token}</div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <footer class="border-t border-gray-200 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 text-sm text-gray-500">
            Built with Laravel, Tailwind, Alpine, and AJAX.
        </div>
    </footer>
</div>
</body>
</html>
