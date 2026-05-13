<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Notifications\RsvpReceivedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class InviteController extends Controller
{
    private function tokenIsExpired(Guest $guest): bool
    {
        if (! $guest->relationLoaded('event')) {
            $guest->load('event');
        }

        if ($guest->event) {
            $now = Carbon::now($guest->event->timezone);
            $eventStart = Carbon::parse($guest->event->event_start)->timezone($guest->event->timezone);

            if ($now->greaterThanOrEqualTo($eventStart)) {
                return true;
            }
        }

        $ttl = config('invitations.token_ttl_days');

        if ($ttl === null || $ttl === '') {
            return false;
        }

        $days = (int) $ttl;

        if ($days <= 0) {
            return false;
        }

        if ($guest->created_at && $guest->created_at->lt(Carbon::now()->subDays($days))) {
            return true;
        }

        return false;
    }

    public function show(string $token)
    {
        $guest = Guest::with(['event', 'rsvp'])->where('invite_token', $token)->firstOrFail();

        if ($this->tokenIsExpired($guest)) {
            return response()->view('invite.expired', ['guest' => $guest], 410);
        }

        if ($guest->rsvp !== null || $guest->status !== 'pending') {
            return view('invite.edit', ['guest' => $guest]);
        }

        return view('invite.show', ['guest' => $guest]);
    }

    public function store(string $token, Request $request)
    {
        $guest = Guest::with(['event', 'rsvp'])->where('invite_token', $token)->firstOrFail();

        if ($this->tokenIsExpired($guest)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invite expired'], 410);
            }

            return response()->view('invite.expired', ['guest' => $guest], 410);
        }

        $event = $guest->event;

        $settings = (array) ($event->settings ?? []);
        $allowPlusOnes = (bool) Arr::get($settings, 'allow_plus_ones', false);
        $customQuestions = Arr::get($settings, 'custom_questions', []);

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

        $status = $data['status'];
        $attendingCount = $status === 'accepted'
            ? ($allowPlusOnes ? (int) ($data['attending_count'] ?? 1) : 1)
            : 0;

        if ($status === 'accepted' && $event->capacity !== null) {
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

        $answers = $status === 'accepted' ? ($data['answers'] ?? null) : null;

        $guest->rsvp()->updateOrCreate(
            ['guest_id' => $guest->id],
            [
                'attending_count' => $attendingCount,
                'answers' => $answers,
            ]
        );

        $guest->status = $status;
        $guest->save();

        if (config('invitations.notify_admin_on_rsvp', true) && $event->user) {
            $event->user->notify(new RsvpReceivedNotification(
                $event,
                $guest,
                $status,
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

    public function qr(string $token)
    {
        $guest = Guest::where('invite_token', $token)->firstOrFail();

        if ($this->tokenIsExpired($guest)) {
            abort(404);
        }

        $png = QrCode::format('png')->size(300)->generate($guest->invite_url);

        return response($png)->header('Content-Type', 'image/png');
    }
}
