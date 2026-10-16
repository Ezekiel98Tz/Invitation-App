<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CelebrationController;
use App\Http\Controllers\Admin\CheckInController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\CredentialController;
use App\Http\Controllers\Admin\DeliveryLabController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\GuestController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\PledgeController;
use App\Http\Controllers\Admin\PledgeImportController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventTypeController;
use App\Http\Controllers\Api\InvitationDesignController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\Webhooks\DeliveryWebhookController;
use App\Http\Middleware\EnsureEventCapability;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| These routes are loaded from routes/web.php so they inherit the "web"
| middleware group (session + CSRF). This provides same-origin, cookie-based
| authentication for the React SPA without requiring a token library.
|
*/

Route::prefix('api/v1')->group(function () {
    // Ensures the session and XSRF-TOKEN cookie are established for the SPA.
    Route::get('csrf', fn () => response()->json(['ok' => true]))->name('api.csrf');

    Route::post('auth/login', [AuthController::class, 'login'])->name('api.auth.login');
    Route::post('auth/register', [AuthController::class, 'register'])->name('api.auth.register');
    Route::get('templates', [InvitationDesignController::class, 'templates'])->name('api.templates');
    Route::get('event-types', [EventTypeController::class, 'index'])->name('api.event-types');

    Route::middleware('auth')->group(function () {
        Route::get('me', [AuthController::class, 'me'])->name('api.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        Route::middleware('throttle:admin-read')->group(function () {
            Route::get('events', [EventController::class, 'index'])->name('api.events.index');
            Route::get('events/{event}', [EventController::class, 'show'])->name('api.events.show');
        });

        Route::middleware('throttle:admin-write')->group(function () {
            Route::post('events', [EventController::class, 'store'])->name('api.events.store');
            Route::put('events/{event}', [EventController::class, 'update'])->name('api.events.update');
            Route::patch('events/{event}', [EventController::class, 'update']);
            Route::delete('events/{event}', [EventController::class, 'destroy'])->name('api.events.destroy');
            Route::post('events/{event}/duplicate', [EventController::class, 'duplicate'])->name('api.events.duplicate');
        });

        Route::middleware('throttle:admin-read')->group(function () {
            Route::get('events/{event}/invitation', [InvitationDesignController::class, 'show'])->name('api.events.invitation.show');
        });
        Route::put('events/{event}/invitation', [InvitationDesignController::class, 'update'])->name('api.events.invitation.update');

        Route::middleware('throttle:admin-read')->group(function () {
            Route::get('events/{event}/guests', [GuestController::class, 'index'])->name('api.events.guests.index');
        });

        Route::middleware('throttle:admin-write')->group(function () {
            Route::post('events/{event}/guests', [GuestController::class, 'store'])->name('api.events.guests.store');
            Route::put('events/{event}/guests/{guest}', [GuestController::class, 'update'])->name('api.events.guests.update');
            Route::delete('events/{event}/guests/{guest}', [GuestController::class, 'destroy'])->name('api.events.guests.destroy');
        });

        Route::middleware('throttle:admin-read')->group(function () {
            Route::get('events/{event}/imports/latest', [GuestController::class, 'importStatus'])->name('api.events.imports.latest');
            Route::get('events/{event}/guests/{guest}/verification', [GuestController::class, 'verification'])->name('api.events.guests.verification');
        });

        // Entry credentials (separate from the RSVP link token). Credentials
        // exist for event entry, so the operator endpoints require the
        // `check_in` capability; the public RSVP flow that issues them is not
        // gated here.
        Route::middleware(EnsureEventCapability::class.':check_in')->group(function () {
            Route::get('events/{event}/guests/{guest}/credential', [CredentialController::class, 'show'])->name('api.events.guests.credential.show');
            Route::middleware('throttle:admin-write')->group(function () {
                Route::post('events/{event}/guests/{guest}/credential', [CredentialController::class, 'issue'])->name('api.events.guests.credential.issue');
                Route::post('events/{event}/guests/{guest}/credential/reissue', [CredentialController::class, 'reissue'])->name('api.events.guests.credential.reissue');
                Route::post('events/{event}/guests/{guest}/credential/revoke', [CredentialController::class, 'revoke'])->name('api.events.guests.credential.revoke');
                Route::post('events/{event}/guests/{guest}/credential/reveal', [CredentialController::class, 'reveal'])->name('api.events.guests.credential.reveal');
            });
            Route::middleware('throttle:admin-send')->group(function () {
                Route::post('events/{event}/guests/{guest}/credential/resend', [CredentialController::class, 'resend'])->name('api.events.guests.credential.resend');
            });
        });

        // Event-day check-in / attendance. Lookup is read-only; only an explicit
        // check-in records attendance. All of these require the `check_in`
        // capability for the event.
        Route::middleware(EnsureEventCapability::class.':check_in')->group(function () {
            Route::middleware('throttle:admin-read')->group(function () {
                Route::get('events/{event}/check-in/lookup', [CheckInController::class, 'lookup'])->name('api.events.check-in.lookup');
                Route::get('events/{event}/attendances', [CheckInController::class, 'index'])->name('api.events.attendances.index');
            });
            Route::middleware('throttle:admin-write')->group(function () {
                Route::post('events/{event}/guests/{guest}/check-in', [CheckInController::class, 'store'])->name('api.events.guests.check-in');
                Route::post('events/{event}/attendances/{attendance}/reverse', [CheckInController::class, 'reverse'])->name('api.events.attendances.reverse');
            });
        });

        Route::middleware('throttle:admin-heavy')->group(function () {
            Route::post('events/{event}/guests/import', [GuestController::class, 'import'])->name('api.events.guests.import');
            Route::get('events/{event}/guests/export', [GuestController::class, 'export'])->name('api.events.guests.export');
            Route::get('reports/events/{event}/export', [ReportController::class, 'export'])->name('api.reports.events.export');
        });

        Route::middleware('throttle:admin-send')->group(function () {
            Route::post('events/{event}/guests/{guest}/resend', [GuestController::class, 'resend'])->name('api.events.guests.resend');
            Route::post('events/{event}/guests/{guest}/remind', [GuestController::class, 'remind'])->name('api.events.guests.remind');
            Route::post('events/{event}/guests/reminders', [GuestController::class, 'sendReminders'])->name('api.events.guests.reminders');
            Route::post('events/{event}/invitations/send', [InvitationController::class, 'send'])->name('api.events.invitations.send');
            Route::post('events/{event}/deliveries/{delivery}/retry', [InvitationController::class, 'retry'])->name('api.events.deliveries.retry');
            Route::post('events/{event}/deliveries/retry-failed', [InvitationController::class, 'retryFailed'])->name('api.events.deliveries.retryFailed');
        });

        Route::middleware('throttle:admin-read')->group(function () {
            Route::get('events/{event}/invitations/preview', [InvitationController::class, 'preview'])->name('api.events.invitations.preview');
            Route::get('events/{event}/deliveries', [InvitationController::class, 'deliveries'])->name('api.events.deliveries');
            Route::get('events/{event}/deliveries/failed', [InvitationController::class, 'failed'])->name('api.events.deliveries.failed');
            Route::get('reports/events', [ReportController::class, 'events'])->name('api.reports.events');
        });

        // Delivery Lab (operator-only; sandbox/testing).
        Route::middleware('throttle:admin-read')->group(function () {
            Route::get('delivery-lab', [DeliveryLabController::class, 'index'])->name('api.delivery-lab.index');
            Route::get('events/{event}/guests/{guest}/delivery-lab/preview', [DeliveryLabController::class, 'preview'])->name('api.delivery-lab.preview');
        });
        Route::middleware('throttle:admin-send')->group(function () {
            Route::post('events/{event}/guests/{guest}/delivery-lab/test-send', [DeliveryLabController::class, 'testSend'])->name('api.delivery-lab.test-send');
        });

        Route::middleware('throttle:admin-read')->group(function () {
            Route::get('celebrations', [CelebrationController::class, 'index'])->name('api.celebrations.index');
            Route::get('celebrations/{celebration}', [CelebrationController::class, 'show'])->name('api.celebrations.show');
        });

        Route::middleware('throttle:admin-write')->group(function () {
            Route::post('celebrations', [CelebrationController::class, 'store'])->name('api.celebrations.store');
            Route::put('celebrations/{celebration}', [CelebrationController::class, 'update'])->name('api.celebrations.update');
            Route::patch('celebrations/{celebration}', [CelebrationController::class, 'update']);
            Route::delete('celebrations/{celebration}', [CelebrationController::class, 'destroy'])->name('api.celebrations.destroy');

            Route::post('celebrations/{celebration}/events/{event}', [CelebrationController::class, 'attachEvent'])->name('api.celebrations.events.attach');
            Route::delete('celebrations/{celebration}/events/{event}', [CelebrationController::class, 'detachEvent'])->name('api.celebrations.events.detach');
        });

        Route::middleware('throttle:admin-read')->group(function () {
            Route::get('contacts', [ContactController::class, 'index'])->name('api.contacts.index');
            Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('api.contacts.show');
            Route::get('contacts/{contact}/guests', [ContactController::class, 'guests'])->name('api.contacts.guests');
        });

        Route::middleware('throttle:admin-write')->group(function () {
            Route::put('contacts/{contact}', [ContactController::class, 'update'])->name('api.contacts.update');
            Route::patch('contacts/{contact}', [ContactController::class, 'update']);
            Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('api.contacts.destroy');
        });

        // Pledges (information management only — no money is moved or held).
        // The summary route is registered before the {pledge} detail route so
        // "summary" can never be captured as a pledge id.
        Route::middleware(['throttle:admin-read', EnsureEventCapability::class.':contributions'])->group(function () {
            Route::get('events/{event}/pledges/summary', [PledgeController::class, 'summary'])->name('api.events.pledges.summary');
        });

        // Pledge spreadsheet import/export (information management only).
        // Registered before the {pledge} detail route so literal segments such
        // as "export", "import" and "template" are never captured as a pledge id.
        Route::middleware(['throttle:admin-heavy', EnsureEventCapability::class.':contributions'])->group(function () {
            Route::get('events/{event}/pledges/export', [PledgeImportController::class, 'export'])->name('api.events.pledges.export');
            Route::get('events/{event}/pledges/import/template', [PledgeImportController::class, 'template'])->name('api.events.pledges.import.template');
            Route::get('events/{event}/pledges/import/{batch}/errors', [PledgeImportController::class, 'errors'])->name('api.events.pledges.import.errors');
        });

        Route::middleware(['throttle:admin-write', EnsureEventCapability::class.':contributions'])->group(function () {
            Route::post('events/{event}/pledges/import/upload', [PledgeImportController::class, 'upload'])->name('api.events.pledges.import.upload');
            Route::post('events/{event}/pledges/import/{batch}/preview', [PledgeImportController::class, 'preview'])->name('api.events.pledges.import.preview');
            Route::post('events/{event}/pledges/import/{batch}/confirm', [PledgeImportController::class, 'confirm'])->name('api.events.pledges.import.confirm');
        });

        Route::middleware(['throttle:admin-read', EnsureEventCapability::class.':contributions'])->group(function () {
            Route::get('events/{event}/pledges/import/{batch}', [PledgeImportController::class, 'show'])->name('api.events.pledges.import.show');
        });

        Route::middleware(['throttle:admin-read', EnsureEventCapability::class.':contributions'])->group(function () {
            Route::get('events/{event}/pledges', [PledgeController::class, 'index'])->name('api.events.pledges.index');
            Route::get('events/{event}/pledges/{pledge}', [PledgeController::class, 'show'])->name('api.events.pledges.show');
        });

        Route::middleware(['throttle:admin-write', EnsureEventCapability::class.':contributions'])->group(function () {
            Route::post('events/{event}/pledges', [PledgeController::class, 'store'])->name('api.events.pledges.store');
            Route::patch('events/{event}/pledges/{pledge}', [PledgeController::class, 'update'])->name('api.events.pledges.update');
            Route::post('events/{event}/pledges/{pledge}/cancel', [PledgeController::class, 'cancel'])->name('api.events.pledges.cancel');
            Route::post('events/{event}/pledges/{pledge}/fulfillments', [PledgeController::class, 'storeFulfillment'])->name('api.events.pledges.fulfillments.store');
            Route::post('events/{event}/pledges/{pledge}/fulfillments/{fulfillment}/reverse', [PledgeController::class, 'reverseFulfillment'])->name('api.events.pledges.fulfillments.reverse');
        });

        Route::middleware('throttle:admin-read')->group(function () {
            // Account/celebration pledge reports aggregate across many events,
            // so there is no single event capability to check. They stay
            // scoped by ownership only; per-event capability gating applies to
            // the event-scoped pledge endpoints above.
            Route::get('reports/pledges', [PledgeController::class, 'accountReport'])->name('api.reports.pledges');
            Route::get('reports/celebrations/{celebration}/pledges', [PledgeController::class, 'celebrationReport'])->name('api.reports.celebrations.pledges');
        });

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('api.audit-logs.index');

        Route::get('staff', [StaffController::class, 'index'])->name('api.staff.index');
        Route::post('staff/invite', [StaffController::class, 'invite'])->name('api.staff.invite');
    });

    // Public invitation / RSVP (token-scoped, throttled per token).
    Route::middleware('throttle:invite-token')->group(function () {
        Route::get('public/invites/{token}', [InviteController::class, 'apiShow'])->name('api.invites.show');
        Route::post('public/invites/{token}', [InviteController::class, 'store'])->name('api.invites.store');
        Route::get('public/invites/{token}/qr', [InviteController::class, 'qr'])->name('api.invites.qr');
    });

    // Delivery provider callbacks. Excluded from CSRF in bootstrap/app.php and
    // verified with a shared secret in the controller (fails closed).
    Route::post('webhooks/delivery/{channel}', [DeliveryWebhookController::class, 'store'])
        ->name('webhooks.delivery.store');
});
