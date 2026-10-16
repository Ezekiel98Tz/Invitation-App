<?php

namespace App\Http\Middleware;

use App\Models\Event;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side capability gate for optional event modules.
 *
 * Frontend hiding is not authorization: a capability that is disabled for an
 * event must be denied at the API even if a client calls the endpoint
 * directly. This middleware is applied to the event-scoped endpoints of an
 * implemented capability (for example `EnsureEventCapability:contributions`)
 * and fails closed — an unknown, unimplemented or disabled capability resolves
 * to `false` and the request is denied with HTTP 403.
 *
 * The event is resolved from the `{event}` route parameter (already substituted
 * by route-model binding, or looked up defensively if this middleware runs
 * before `SubstituteBindings`), so it never trusts a client-supplied value.
 */
class EnsureEventCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $event = $request->route('event');

        if (! $event instanceof Event) {
            $event = $event === null ? null : Event::find($event);
        }

        if (! $event instanceof Event) {
            abort(404);
        }

        if (! (bool) ($event->capabilities[$capability] ?? false)) {
            abort(403, "The {$capability} capability is not enabled for this event.");
        }

        return $next($request);
    }
}
