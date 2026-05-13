<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\InviteController;
use App\Http\Controllers\Admin\EventController as AdminEventController;
use App\Http\Controllers\Admin\GuestController as AdminGuestController;
use App\Http\Controllers\Admin\InvitationController as AdminInvitationController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Admin\StaffController as AdminStaffController;
use App\Http\Controllers\Webhooks\DeliveryWebhookController;
use App\Http\Controllers\StaffInviteController;
use App\Models\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/events/{event}', function (Event $event) {
        Gate::authorize('view', $event);

        return view('admin.events.show', ['event' => $event]);
    })->name('ui.events.show');

    Route::get('/audit-logs', function () {
        return view('admin.audit-logs');
    })->name('ui.audit-logs');

    Route::get('/staff', function () {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('admin.staff');
    })->name('ui.staff');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

Route::middleware('throttle:invite-token')->group(function () {
    Route::get('/invite/{token}', [InviteController::class, 'show'])->name('invites.show');
    Route::post('/invite/{token}', [InviteController::class, 'store'])->name('invites.store');
    Route::get('/invite/{token}/qr', [InviteController::class, 'qr'])->name('invites.qr');
});

Route::middleware('auth')->prefix('admin')->group(function () {
    Route::middleware('throttle:admin-write')->group(function () {
        Route::post('events', [AdminEventController::class, 'store'])->name('events.store');
        Route::put('events/{event}', [AdminEventController::class, 'update'])->name('events.update');
        Route::patch('events/{event}', [AdminEventController::class, 'update']);
        Route::delete('events/{event}', [AdminEventController::class, 'destroy'])->name('events.destroy');
        Route::post('events/{event}/duplicate', [AdminEventController::class, 'duplicate'])->name('admin.events.duplicate');
    });
    Route::get('events', [AdminEventController::class, 'index'])->name('events.index');

    Route::get('events/{event}/guests', [AdminGuestController::class, 'index'])->name('admin.events.guests.index');
    Route::middleware('throttle:admin-heavy')->group(function () {
        Route::post('events/{event}/guests/import', [AdminGuestController::class, 'import'])->name('admin.events.guests.import');
        Route::get('events/{event}/guests/export', [AdminGuestController::class, 'export'])->name('admin.events.guests.export');
        Route::get('reports/events/{event}/export', [AdminReportController::class, 'export'])->name('admin.reports.events.export');
    });
    Route::middleware('throttle:admin-send')->group(function () {
        Route::post('events/{event}/guests/{guest}/resend', [AdminGuestController::class, 'resend'])->name('admin.events.guests.resend');
        Route::post('events/{event}/guests/reminders', [AdminGuestController::class, 'sendReminders'])->name('admin.events.guests.reminders');
        Route::post('events/{event}/invitations/send', [AdminInvitationController::class, 'send'])->name('admin.events.invitations.send');
        Route::post('events/{event}/deliveries/{delivery}/retry', [AdminInvitationController::class, 'retry'])->name('admin.events.deliveries.retry');
        Route::post('events/{event}/deliveries/retry-failed', [AdminInvitationController::class, 'retryFailed'])->name('admin.events.deliveries.retryFailed');
    });

    Route::get('reports/events', [AdminReportController::class, 'events'])->name('admin.reports.events');

    Route::get('events/{event}/invitations/preview', [AdminInvitationController::class, 'preview'])->name('admin.events.invitations.preview');
    Route::get('events/{event}/deliveries', [AdminInvitationController::class, 'deliveries'])->name('admin.events.deliveries');
    Route::get('events/{event}/deliveries/failed', [AdminInvitationController::class, 'failed'])->name('admin.events.deliveries.failed');

    Route::get('audit-logs', [AdminAuditLogController::class, 'index'])->name('admin.audit-logs.index');

    Route::get('staff', [AdminStaffController::class, 'index'])->name('admin.staff.index');
    Route::post('staff/invite', [AdminStaffController::class, 'invite'])->name('admin.staff.invite');
});

Route::post('/webhooks/delivery/{channel}', [DeliveryWebhookController::class, 'store'])->name('webhooks.delivery.store');

Route::middleware('auth')->group(function () {
    Route::post('/staff/invites/{token}/accept', [StaffInviteController::class, 'accept'])->name('staff-invites.accept');
});
