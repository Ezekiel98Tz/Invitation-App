<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
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
        RateLimiter::for('invite-token', function (Request $request) {
            $token = (string) $request->route('token');

            return Limit::perMinute(5)->by($token);
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
