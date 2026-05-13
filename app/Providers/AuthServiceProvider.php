<?php

namespace App\Providers;

use App\Models\Event;
use App\Models\Guest;
use App\Policies\EventPolicy;
use App\Policies\GuestPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Guest::class, GuestPolicy::class);
    }
}
