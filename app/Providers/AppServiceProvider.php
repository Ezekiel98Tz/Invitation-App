<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.browser_testing') && app()->environment('testing')) {
            Http::preventStrayRequests();
        }

        RateLimiter::for('invite-token', function (Request $request) {
            $token = (string) $request->route('token');

            // Two independent caps. The per-client-address cap prevents an
            // attacker from getting a fresh bucket for every guessed token
            // (the previous single per-token bucket made enumeration free);
            // the per-token cap still bounds hammering a single known token.
            // Returning an array enforces every limit.
            return [
                Limit::perMinute((int) config('invitations.rate_limits.invite_token_ip_per_minute', 30))
                    ->by('invite-ip:'.$request->ip()),
                Limit::perMinute((int) config('invitations.rate_limits.invite_token_per_minute', 5))
                    ->by('invite-token:'.$token),
            ];
        });

        RateLimiter::for('admin-read', function (Request $request) {
            $userId = optional($request->user())->id;
            $key = ($userId ? 'u:'.$userId : 'guest').':'.$request->ip();

            $limit = (int) config('invitations.rate_limits.admin_read_per_minute', 120);

            return Limit::perMinute($limit)->by($key);
        });

        RateLimiter::for('admin-write', function (Request $request) {
            $userId = optional($request->user())->id;
            $key = ($userId ? 'u:'.$userId : 'guest').':'.$request->ip();

            $limit = (int) config('invitations.rate_limits.admin_write_per_minute', 60);

            return Limit::perMinute($limit)->by($key);
        });

        RateLimiter::for('admin-send', function (Request $request) {
            $userId = optional($request->user())->id;
            $key = ($userId ? 'u:'.$userId : 'guest').':'.$request->ip();

            $limit = (int) config('invitations.rate_limits.admin_send_per_minute', 20);

            return Limit::perMinute($limit)->by($key);
        });

        RateLimiter::for('admin-heavy', function (Request $request) {
            $userId = optional($request->user())->id;
            $key = ($userId ? 'u:'.$userId : 'guest').':'.$request->ip();

            $limit = (int) config('invitations.rate_limits.admin_heavy_per_minute', 10);

            return Limit::perMinute($limit)->by($key);
        });
    }
}
