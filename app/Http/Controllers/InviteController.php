<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Notifications\RsvpReceivedNotification;
use App\Support\InvitationAccess;
use App\Support\InvitationDesign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class InviteController extends Controller
{
    public function show(string $token)
    {
        $guest = Guest::with(['event', 'rsvp'])->where('invite_token', $token)->firstOrFail();

        if (InvitationAccess::status($guest) !== InvitationAccess::AVAILABLE) {
            return response()->view('invite.expired', ['guest' => $guest], 410);
        }

        if ($guest->rsvp !== null || $guest->status !== 'pending') {
            return view('invite.edit', ['guest' => $guest]);
        }

        return view('invite.show', ['guest' => $guest]);
    }

    public function apiShow(string $token): JsonResponse
    {
        $guest = Guest::with(['event', 'rsvp'])->where('invite_token', $token)->firstOrFail();

        $status = InvitationAccess::status($guest);

        if ($status !== InvitationAccess::AVAILABLE) {
            return response()->json([
                'message' => $status === InvitationAccess::UNAVAILABLE ? 'Invite unavailable' : 'Invite expired',
            ], 410);
        }

        $event = $guest->event;
        $settings = (array) ($event->settings ?? []);
        $capabilities = $event->capabilities;

        return response()->json([
            'guest' => [
                'id' => $guest->id,
                'name' => $guest->name,
                'status' => $guest->status,
            ],
            'rsvp' => $guest->rsvp ? [
                'attending_count' => $guest->rsvp->attending_count,
                'answers' => $guest->rsvp->answers,
            ] : null,
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'description' => $event->description,
                'event_start' => $event->event_start?->toIso8601String(),
                'timezone' => $event->timezone,
                'venue' => $event->venue,
                'capacity' => $event->capacity,
                'event_type' => $event->event_type,
            ],
            'settings' => [
                'allow_plus_ones' => $capabilities['plus_ones'],
                'custom_questions' => $capabilities['custom_questions'] ? Arr::get($settings, 'custom_questions', []) : [],
                'capabilities' => $capabilities,
                'design' => InvitationDesign::forEvent($event),
            ],
        ]);
    }

    public function store(string $token, Request $request)
    {
        $guest = Guest::with(['event', 'rsvp'])->where('invite_token', $token)->firstOrFail();

        $status = InvitationAccess::status($guest);

        if ($status !== InvitationAccess::AVAILABLE) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $status === InvitationAccess::UNAVAILABLE ? 'Invite unavailable' : 'Invite expired',
                ], 410);
            }

            return response()->view('invite.expired', ['guest' => $guest], 410);
        }

        $event = $guest->event;

        $settings = (array) ($event->settings ?? []);
        $capabilities = $event->capabilities;
        $allowPlusOnes = (bool) $capabilities['plus_ones'];
        $customQuestions = $capabilities['custom_questions'] ? Arr::get($settings, 'custom_questions', []) : [];

        $rules = [
            'status' => ['required', Rule::in(['accepted', 'declined'])],
        ];

        if ($allowPlusOnes) {
            $rules['attending_count'] = [
                Rule::requiredIf(fn () => $request->input('status') === 'accepted'),
                'integer',
                'min:1',
                'max:5',
            ];
        }

        if (is_array($customQuestions) && $request->input('status') === 'accepted') {
            $rules['answers'] = ['array'];

            foreach ($customQuestions as $question) {
                if (! is_array($question)) {
                    continue;
                }

                $key = (string) ($question['key'] ?? '');
                $type = (string) ($question['type'] ?? 'text');
                $required = (bool) ($question['required'] ?? false);
                $options = $question['options'] ?? [];

                if ($key === '') {
                    continue;
                }

                $answerRules = [];

                if ($required) {
                    $answerRules[] = 'required';
                } else {
                    $answerRules[] = 'nullable';
                }

                if ($type === 'number') {
                    $answerRules[] = 'numeric';
                } elseif ($type === 'boolean') {
                    $answerRules[] = 'boolean';
                } elseif ($type === 'choice') {
                    $answerRules[] = Rule::in(is_array($options) ? $options : []);
                } else {
                    $answerRules[] = 'string';
                }

                $rules["answers.$key"] = $answerRules;
            }
        }

        $data = Validator::make($request->all(), $rules)->validate();

        $rsvpStatus = $data['status'];
        $attendingCount = $rsvpStatus === 'accepted'
            ? ($allowPlusOnes ? (int) ($data['attending_count'] ?? 1) : 1)
            : 0;

        if ($rsvpStatus === 'accepted' && $event->capacity !== null) {
            $currentCount = $guest->status === 'accepted'
                ? ($guest->rsvp?->attending_count ?? 1)
                : 0;

            $delta = $guest->status === 'accepted'
                ? max(0, $attendingCount - $currentCount)
                : $attendingCount;

            if ($delta > 0 && $event->isAtCapacity($delta)) {
                throw ValidationException::withMessages([
                    'status' => ['Event is at capacity.'],
                ]);
            }
        }

        $answers = $rsvpStatus === 'accepted' ? ($data['answers'] ?? null) : null;

        $guest->rsvp()->updateOrCreate(
            ['guest_id' => $guest->id],
            [
                'attending_count' => $attendingCount,
                'answers' => $answers,
            ]
        );

        $guest->status = $rsvpStatus;
        $guest->save();

        // The event-entry credential follows the RSVP decision. The RSVP link
        // token is never touched. Credential handling must never break the RSVP
        // journey, so failures are logged rather than surfaced to the guest.
        try {
            $issuer = app(\App\Services\CredentialIssuer::class);

            if ($rsvpStatus === 'accepted') {
                $issuer->activate($guest);
            } else {
                $issuer->revoke($guest, 'rsvp_declined');
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Credential lifecycle update failed', [
                'guest_id' => $guest->id,
                'status' => $rsvpStatus,
            ]);
        }

        if (config('invitations.notify_admin_on_rsvp', true) && $event->user) {
            $event->user->notify(new RsvpReceivedNotification(
                $event,
                $guest,
                $rsvpStatus,
                $attendingCount
            ));
        }

        $guest = $guest->fresh(['event', 'rsvp']);

        if ($request->expectsJson()) {
            return response()->json([
                'accepted' => true,
                'guest' => [
                    'id' => $guest->id,
                    'name' => $guest->name,
                    'status' => $guest->status,
                ],
                'rsvp' => [
                    'attending_count' => $guest->rsvp?->attending_count,
                    'answers' => $guest->rsvp?->answers,
                ],
            ]);
        }

        return view('invite.confirmed', ['guest' => $guest]);
    }

    /**
     * Guest-specific QR code for the invitation URL.
     *
     * Rendered as SVG so it does not depend on the Imagick PHP extension.
     */
    public function qr(string $token)
    {
        $guest = Guest::where('invite_token', $token)->firstOrFail();

        if (InvitationAccess::status($guest) !== InvitationAccess::AVAILABLE) {
            abort(404);
        }

        $svg = QrCode::format('svg')->size(300)->generate($guest->invite_url_absolute);

        return response($svg)->header('Content-Type', 'image/svg+xml');
    }
}
