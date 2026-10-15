<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CelebrationController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\DeliveryLabController;
use App\Http\Controllers\Admin\EventController;
use App\Http\Controllers\Admin\GuestController;
use App\Http\Controllers\Admin\InvitationController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EventTypeController;
use App\Http\Controllers\Api\InvitationDesignController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\Webhooks\DeliveryWebhookController;
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

        Route::get('events', [EventController::class, 'index'])->name('api.events.index');
        Route::get('events/{event}', [EventController::class, 'show'])->name('api.events.show');

        Route::middleware('throttle:admin-write')->group(function () {
            Route::post('events', [EventController::class, 'store'])->name('api.events.store');
            Route::put('events/{event}', [EventController::class, 'update'])->name('api.events.update');
            Route::patch('events/{event}', [EventController::class, 'update']);
            Route::delete('events/{event}', [EventController::class, 'destroy'])->name('api.events.destroy');
            Route::post('events/{event}/duplicate', [EventController::class, 'duplicate'])->name('api.events.duplicate');
        });

        Route::get('events/{event}/invitation', [InvitationDesignController::class, 'show'])->name('api.events.invitation.show');
        Route::put('events/{event}/invitation', [InvitationDesignController::class, 'update'])->name('api.events.invitation.update');

        Route::get('events/{event}/guests', [GuestController::class, 'index'])->name('api.events.guests.index');

        Route::middleware('throttle:admin-write')->group(function () {
            Route::post('events/{event}/guests', [GuestController::class, 'store'])->name('api.events.guests.store');
            Route::put('events/{event}/guests/{guest}', [GuestController::class, 'update'])->name('api.events.guests.update');
            Route::delete('events/{event}/guests/{guest}', [GuestController::class, 'destroy'])->name('api.events.guests.destroy');
        });

        Route::get('events/{event}/imports/latest', [GuestController::class, 'importStatus'])->name('api.events.imports.latest');

        Route::get('events/{event}/guests/{guest}/verification', [GuestController::class, 'verification'])->name('api.events.guests.verification');

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

        Route::get('events/{event}/invitations/preview', [InvitationController::class, 'preview'])->name('api.events.invitations.preview');
        Route::get('events/{event}/deliveries', [InvitationController::class, 'deliveries'])->name('api.events.deliveries');
        Route::get('events/{event}/deliveries/failed', [InvitationController::class, 'failed'])->name('api.events.deliveries.failed');

        Route::get('reports/events', [ReportController::class, 'events'])->name('api.reports.events');

        // Delivery Lab (operator-only; sandbox/testing).
        Route::get('delivery-lab', [DeliveryLabController::class, 'index'])->name('api.delivery-lab.index');
        Route::get('events/{event}/guests/{guest}/delivery-lab/preview', [DeliveryLabController::class, 'preview'])->name('api.delivery-lab.preview');
        Route::middleware('throttle:admin-send')->group(function () {
            Route::post('events/{event}/guests/{guest}/delivery-lab/test-send', [DeliveryLabController::class, 'testSend'])->name('api.delivery-lab.test-send');
        });

        Route::get('celebrations', [CelebrationController::class, 'index'])->name('api.celebrations.index');
        Route::get('celebrations/{celebration}', [CelebrationController::class, 'show'])->name('api.celebrations.show');

        Route::middleware('throttle:admin-write')->group(function () {
            Route::post('celebrations', [CelebrationController::class, 'store'])->name('api.celebrations.store');
            Route::put('celebrations/{celebration}', [CelebrationController::class, 'update'])->name('api.celebrations.update');
            Route::patch('celebrations/{celebration}', [CelebrationController::class, 'update']);
            Route::delete('celebrations/{celebration}', [CelebrationController::class, 'destroy'])->name('api.celebrations.destroy');

            Route::post('celebrations/{celebration}/events/{event}', [CelebrationController::class, 'attachEvent'])->name('api.celebrations.events.attach');
            Route::delete('celebrations/{celebration}/events/{event}', [CelebrationController::class, 'detachEvent'])->name('api.celebrations.events.detach');
        });

        Route::get('contacts', [ContactController::class, 'index'])->name('api.contacts.index');
        Route::get('contacts/{contact}', [ContactController::class, 'show'])->name('api.contacts.show');
        Route::get('contacts/{contact}/guests', [ContactController::class, 'guests'])->name('api.contacts.guests');

        Route::middleware('throttle:admin-write')->group(function () {
            Route::put('contacts/{contact}', [ContactController::class, 'update'])->name('api.contacts.update');
            Route::patch('contacts/{contact}', [ContactController::class, 'update']);
            Route::delete('contacts/{contact}', [ContactController::class, 'destroy'])->name('api.contacts.destroy');
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
