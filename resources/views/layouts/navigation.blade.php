<header
    x-data="{ open: false, accountOpen: false }"
    x-on:keydown.escape.window="if (open) { open = false; $nextTick(() => $refs.mobileToggle.focus()) } else if (accountOpen) { accountOpen = false; $nextTick(() => $refs.accountToggle.focus()) }"
    class="studio-legacy-nav"
>
    <div class="studio-legacy-nav-inner">
        <div class="studio-legacy-nav-main">
            <a href="{{ route('dashboard', absolute: false) }}" class="studio-brand" aria-label="Invitation Studio — Events">
                <svg viewBox="0 0 32 32" fill="none" aria-hidden="true" focusable="false">
                    <path d="M5.5 7.5a2 2 0 0 1 2-2h6.8a4 4 0 0 1 4 4V26a3.5 3.5 0 0 0-3.5-3.5H7.5a2 2 0 0 1-2-2v-13Z" />
                    <path d="M26.5 7.5a2 2 0 0 0-2-2h-6.2M18.3 9.5V26a3.5 3.5 0 0 1 3.5-3.5h2.7a2 2 0 0 0 2-2v-13Z" />
                    <path d="M9 10h4M9 13.5h4M21 10h1" />
                </svg>
                <span>Invitation Studio</span>
            </a>

            <div class="studio-legacy-desktop-links">
                <nav class="studio-legacy-workspace-links" aria-label="Workspace">
                    <x-nav-link :href="route('dashboard', absolute: false)" :active="request()->routeIs('dashboard') || request()->is('events/*')">
                        Events
                    </x-nav-link>
                    <x-nav-link :href="url('/celebrations')" :active="request()->is('celebrations*')">
                        Celebrations
                    </x-nav-link>
                    <x-nav-link :href="url('/contacts')" :active="request()->is('contacts*')">
                        Contacts
                    </x-nav-link>
                    <x-nav-link :href="url('/delivery-lab')" :active="request()->is('delivery-lab')">
                        Delivery Lab
                    </x-nav-link>
                </nav>
                @if(Auth::user()->isAdmin())
                    <nav class="studio-legacy-admin-links" aria-label="Administration">
                        <span class="studio-legacy-admin-label">Admin</span>
                        <x-nav-link :href="route('ui.audit-logs', absolute: false)" :active="request()->routeIs('ui.audit-logs')">
                            Audit Log
                        </x-nav-link>
                        <x-nav-link :href="route('ui.staff', absolute: false)" :active="request()->routeIs('ui.staff')">
                            Staff
                        </x-nav-link>
                    </nav>
                @endif
            </div>
        </div>

        <div class="studio-legacy-account" x-on:click.outside="accountOpen = false">
            <button
                x-ref="accountToggle"
                type="button"
                class="studio-account-trigger{{ request()->routeIs('profile.edit') ? ' is-active' : '' }}"
                aria-controls="legacy-account-popover"
                x-bind:aria-expanded="accountOpen"
                x-on:click="accountOpen = !accountOpen"
            >
                <span>{{ Auth::user()->name }}</span>
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">
                    <path d="m5.5 7.5 4.5 4.5 4.5-4.5" />
                </svg>
            </button>
            <div id="legacy-account-popover" x-show="accountOpen" x-cloak style="display: none" class="studio-account-popover">
                <a
                    href="{{ route('profile.edit', absolute: false) }}"
                    class="studio-account-link{{ request()->routeIs('profile.edit') ? ' is-active' : '' }}"
                    @if(request()->routeIs('profile.edit')) aria-current="page" @endif
                    x-on:click="accountOpen = false"
                >
                    Profile
                </a>
                <form method="POST" action="{{ route('logout', absolute: false) }}">
                    @csrf
                    <button type="submit" class="studio-account-link">Sign out</button>
                </form>
            </div>
        </div>

        <button
            x-ref="mobileToggle"
            type="button"
            class="studio-mobile-toggle"
            aria-controls="legacy-mobile-navigation"
            x-bind:aria-expanded="open"
            x-on:click="open = !open"
        >
            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                <path x-show="!open" d="M4 6.5h16M4 12h16M4 17.5h16" />
                <path x-show="open" style="display: none" d="m6 6 12 12M18 6 6 18" />
            </svg>
            <span x-text="open ? 'Close menu' : 'Menu'"></span>
        </button>
    </div>

    <div
        id="legacy-mobile-navigation"
        x-show="open"
        x-cloak
        style="display: none"
        x-bind:class="{ 'is-open': open }"
        class="studio-mobile-panel"
    >
        <div class="studio-mobile-panel-head">
            <span>Navigation</span>
            <button
                type="button"
                class="studio-mobile-close"
                x-on:click="open = false; $nextTick(() => $refs.mobileToggle.focus())"
            >
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" focusable="false">
                    <path d="m6 6 12 12M18 6 6 18" />
                </svg>
                <span>Close menu</span>
            </button>
        </div>

        <nav class="studio-mobile-nav-links" aria-label="Workspace">
            <x-responsive-nav-link :href="route('dashboard', absolute: false)" :active="request()->routeIs('dashboard') || request()->is('events/*')" x-on:click="open = false">
                Events
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="url('/celebrations')" :active="request()->is('celebrations*')" x-on:click="open = false">
                Celebrations
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="url('/contacts')" :active="request()->is('contacts*')" x-on:click="open = false">
                Contacts
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="url('/delivery-lab')" :active="request()->is('delivery-lab')" x-on:click="open = false">
                Delivery Lab
            </x-responsive-nav-link>
        </nav>
        @if(Auth::user()->isAdmin())
            <div class="studio-mobile-admin-group">
                <h2 class="studio-mobile-section-label">Administration</h2>
                <nav class="studio-mobile-nav-links" aria-label="Administration">
                    <x-responsive-nav-link :href="route('ui.audit-logs', absolute: false)" :active="request()->routeIs('ui.audit-logs')" x-on:click="open = false">
                        Audit Log
                    </x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('ui.staff', absolute: false)" :active="request()->routeIs('ui.staff')" x-on:click="open = false">
                        Staff
                    </x-responsive-nav-link>
                </nav>
            </div>
        @endif

        <div class="studio-mobile-account">
            <div class="studio-mobile-user">
                <span class="studio-mobile-user-name">{{ Auth::user()->name }}</span>
                <span class="studio-mobile-user-email">{{ Auth::user()->email }}</span>
            </div>
            <a
                href="{{ route('profile.edit', absolute: false) }}"
                class="studio-account-link{{ request()->routeIs('profile.edit') ? ' is-active' : '' }}"
                @if(request()->routeIs('profile.edit')) aria-current="page" @endif
                x-on:click="open = false"
            >
                Profile
            </a>
            <form method="POST" action="{{ route('logout', absolute: false) }}">
                @csrf
                <button type="submit" class="studio-account-link">Sign out</button>
            </form>
        </div>
    </div>
</header>
