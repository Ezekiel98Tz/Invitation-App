<?php

namespace App\Services;

use App\Models\Celebration;
use App\Models\Contact;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Pledge;
use App\Models\PledgeFulfillment;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The pledge ledger.
 *
 * Records pledge information and reported fulfillments only. It never moves,
 * holds, transfers or verifies money, and it never reads or writes RSVP,
 * invitation, credential or check-in state. An outstanding pledge can therefore
 * never block any of those workflows.
 *
 * Design decisions (documented deliberately):
 *
 *  - Amounts are integer minor units with an explicit currency. Floats are
 *    never used.
 *  - Currency is fixed per pledge. A pledge and all of its fulfillments share
 *    one currency; the currency cannot be changed once a fulfillment exists.
 *  - Over-fulfillment is allowed and surfaced as `over_fulfilled`. Reported
 *    amounts are never clamped or rejected for exceeding the pledge, because the
 *    ledger records what the client reports, not what the system expects.
 *  - History is append-only. A reversal is a new compensating row that
 *    references the original (`reverses_fulfillment_id`); the original row is
 *    never edited or deleted.
 */
class PledgeLedger
{
    /**
     * Currency must be a three-letter ISO-4217 code.
     */
    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';

    public function create(Request $request, Event $event, array $data): Pledge
    {
        $currency = $this->normalizeCurrency($data['currency'] ?? null);
        $amount = $this->normalizePledgedAmount($data['pledged_amount_minor'] ?? null);

        $guest = $this->resolveGuest($event, $data['guest_id'] ?? null);
        $contact = $this->resolveContact((int) $event->user_id, $data['contact_id'] ?? null);

        return DB::transaction(function () use ($request, $event, $data, $currency, $amount, $guest, $contact) {
            $pledge = new Pledge();
            $pledge->user_id = $event->user_id;
            $pledge->event_id = $event->id;
            $pledge->guest_id = $guest?->id;
            $pledge->contact_id = $contact?->id;

            $pledge->pledger_name = $this->resolveSnapshotName($data, $guest, $contact);
            $pledge->pledger_email = $this->cleanOptional($data['pledger_email'] ?? null)
                ?? $guest?->email
                ?? $contact?->email;
            $pledge->pledger_phone = $this->cleanOptional($data['pledger_phone'] ?? null)
                ?? $guest?->phone
                ?? $contact?->phone;

            $pledge->currency = $currency;
            $pledge->pledged_amount_minor = $amount;
            $pledge->note = $this->cleanOptional($data['note'] ?? null);
            $pledge->source = $this->normalizeSource($data['source'] ?? null);
            $pledge->status = Pledge::STATUS_ACTIVE;
            $pledge->created_by = $request->user()?->id;
            $pledge->save();

            AuditLogger::log($request, 'pledge.create', $pledge, [
                'event_id' => $event->id,
                'currency' => $currency,
                'pledged_amount_minor' => $amount,
                'source' => $pledge->source,
            ]);

            return $pledge;
        });
    }

    /**
     * Explicit, audited edit of mutable pledge information.
     *
     * The owning account, event and status are never client-controlled here
     * (status changes go through cancel()). Currency is immutable once any
     * fulfillment exists.
     */
    public function update(Request $request, Pledge $pledge, array $data): Pledge
    {
        return DB::transaction(function () use ($request, $pledge, $data) {
            $changes = [];

            if (array_key_exists('guest_id', $data)) {
                $guest = $this->resolveGuest($pledge->event, $data['guest_id']);
                $pledge->guest_id = $guest?->id;
                $changes['guest_id'] = $pledge->guest_id;
            }

            if (array_key_exists('contact_id', $data)) {
                $contact = $this->resolveContact((int) $pledge->user_id, $data['contact_id']);
                $pledge->contact_id = $contact?->id;
                $changes['contact_id'] = $pledge->contact_id;
            }

            if (array_key_exists('pledger_name', $data)) {
                $name = $this->cleanOptional($data['pledger_name']);
                if ($name === null) {
                    throw ValidationException::withMessages([
                        'pledger_name' => ['A pledger name is required.'],
                    ]);
                }
                $pledge->pledger_name = $name;
                $changes['pledger_name'] = $name;
            }

            if (array_key_exists('pledger_email', $data)) {
                $pledge->pledger_email = $this->cleanOptional($data['pledger_email']);
                $changes['pledger_email'] = $pledge->pledger_email;
            }

            if (array_key_exists('pledger_phone', $data)) {
                $pledge->pledger_phone = $this->cleanOptional($data['pledger_phone']);
                $changes['pledger_phone'] = $pledge->pledger_phone;
            }

            if (array_key_exists('note', $data)) {
                $pledge->note = $this->cleanOptional($data['note']);
                $changes['note'] = $pledge->note;
            }

            if (array_key_exists('currency', $data)) {
                $currency = $this->normalizeCurrency($data['currency']);
                if ($currency !== $pledge->currency && $this->hasFulfillments($pledge)) {
                    throw ValidationException::withMessages([
                        'currency' => ['The currency cannot be changed once a fulfillment has been recorded.'],
                    ]);
                }
                $pledge->currency = $currency;
                $changes['currency'] = $currency;
            }

            if (array_key_exists('pledged_amount_minor', $data)) {
                $amount = $this->normalizePledgedAmount($data['pledged_amount_minor']);
                $pledge->pledged_amount_minor = $amount;
                $changes['pledged_amount_minor'] = $amount;
            }

            $pledge->save();

            AuditLogger::log($request, 'pledge.update', $pledge, ['changes' => $changes]);

            return $pledge;
        });
    }

    public function cancel(Request $request, Pledge $pledge, ?string $reason = null): Pledge
    {
        if ($pledge->isCancelled()) {
            throw ValidationException::withMessages([
                'pledge' => ['This pledge is already cancelled.'],
            ]);
        }

        return DB::transaction(function () use ($request, $pledge, $reason) {
            $pledge->status = Pledge::STATUS_CANCELLED;
            $pledge->save();

            AuditLogger::log($request, 'pledge.cancel', $pledge, [
                'reason' => $this->cleanOptional($reason),
            ]);

            return $pledge;
        });
    }

    public function recordFulfillment(Request $request, Pledge $pledge, array $data): PledgeFulfillment
    {
        if ($pledge->isCancelled()) {
            throw ValidationException::withMessages([
                'pledge' => ['A fulfillment cannot be recorded against a cancelled pledge.'],
            ]);
        }

        $amount = $this->normalizeFulfillmentAmount($data['amount_minor'] ?? null);
        $method = $this->normalizeMethod($data['method'] ?? null);

        return DB::transaction(function () use ($request, $pledge, $data, $amount, $method) {
            $fulfillment = new PledgeFulfillment();
            $fulfillment->pledge_id = $pledge->id;
            $fulfillment->amount_minor = $amount;
            $fulfillment->method = $method;
            $fulfillment->method_detail = $this->cleanOptional($data['method_detail'] ?? null);
            $fulfillment->received_on = $this->cleanOptional($data['received_on'] ?? null);
            $fulfillment->reference = $this->cleanOptional($data['reference'] ?? null);
            $fulfillment->note = $this->cleanOptional($data['note'] ?? null);
            $fulfillment->recorded_by = $request->user()?->id;
            $fulfillment->status = PledgeFulfillment::STATUS_RECORDED;
            $fulfillment->save();

            AuditLogger::log($request, 'fulfillment.record', $fulfillment, [
                'pledge_id' => $pledge->id,
                'amount_minor' => $amount,
                'method' => $method,
            ]);

            return $fulfillment;
        });
    }

    /**
     * Reverse a recorded fulfillment with a compensating entry.
     *
     * The original row is left exactly as recorded; the compensating row carries
     * the same amount and points back through `reverses_fulfillment_id`. The
     * unique index on that column (plus this check) guarantees an original can
     * be reversed at most once.
     */
    public function reverseFulfillment(Request $request, Pledge $pledge, PledgeFulfillment $fulfillment, array $data): PledgeFulfillment
    {
        if ((int) $fulfillment->pledge_id !== (int) $pledge->id) {
            abort(404);
        }

        if ($fulfillment->isReversal()) {
            throw ValidationException::withMessages([
                'fulfillment' => ['A reversal entry cannot itself be reversed.'],
            ]);
        }

        if ($fulfillment->status !== PledgeFulfillment::STATUS_RECORDED) {
            throw ValidationException::withMessages([
                'fulfillment' => ['This fulfillment is not active.'],
            ]);
        }

        return DB::transaction(function () use ($request, $pledge, $fulfillment, $data) {
            $original = PledgeFulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->first();

            if (! $original || $original->status !== PledgeFulfillment::STATUS_RECORDED || $original->isReversal()) {
                throw ValidationException::withMessages([
                    'fulfillment' => ['This fulfillment has already been reversed.'],
                ]);
            }

            $alreadyReversed = PledgeFulfillment::query()
                ->where('reverses_fulfillment_id', $original->id)
                ->exists();

            if ($alreadyReversed) {
                throw ValidationException::withMessages([
                    'fulfillment' => ['This fulfillment has already been reversed.'],
                ]);
            }

            $reversal = new PledgeFulfillment();
            $reversal->pledge_id = $pledge->id;
            $reversal->amount_minor = (int) $original->amount_minor;
            $reversal->method = $original->method;
            $reversal->method_detail = $original->method_detail;
            $reversal->received_on = $this->cleanOptional($data['received_on'] ?? null) ?? now()->toDateString();
            $reversal->reference = $original->reference;
            $reversal->note = $this->cleanOptional($data['note'] ?? null)
                ?? 'Reversal of fulfillment #'.$original->id;
            $reversal->recorded_by = $request->user()?->id;
            $reversal->status = PledgeFulfillment::STATUS_REVERSED;
            $reversal->reverses_fulfillment_id = $original->id;
            $reversal->save();

            AuditLogger::log($request, 'fulfillment.reverse', $reversal, [
                'pledge_id' => $pledge->id,
                'reverses_fulfillment_id' => $original->id,
                'amount_minor' => (int) $original->amount_minor,
            ]);

            return $reversal;
        });
    }

    /**
     * Derived balances for a single pledge (currency + integer minor units).
     *
     * @return array<string, mixed>
     */
    public function balances(Pledge $pledge): array
    {
        return [
            'currency' => $pledge->currency,
            'pledged_amount_minor' => (int) $pledge->pledged_amount_minor,
            'fulfilled_minor' => $pledge->fulfilledMinor(),
            'outstanding_minor' => $pledge->outstandingMinor(),
            'derived_status' => $pledge->derivedStatus(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function eventSummary(Event $event): array
    {
        $pledges = Pledge::query()
            ->where('event_id', $event->id)
            ->with('fulfillments')
            ->get();

        return $this->aggregate($pledges);
    }

    /**
     * @return array<string, mixed>
     */
    public function accountSummary(int $ownerId): array
    {
        // Only events with `contributions` enabled contribute money to reports —
        // a disabled event's pledge details must not leak through an aggregate.
        $enabledEventIds = $this->contributionEventIds(
            Event::query()->where('user_id', $ownerId)->get()
        );

        $pledges = Pledge::query()
            ->whereIn('event_id', $enabledEventIds)
            ->with(['fulfillments', 'event:id,title'])
            ->get();

        $summary = $this->aggregate($pledges);
        $summary['events'] = $this->eventBreakdown($pledges);

        return $summary;
    }

    /**
     * Celebration totals are an aggregation of the member events' pledges. Only
     * events that belong to the celebration's own account are included.
     *
     * @return array<string, mixed>
     */
    public function celebrationSummary(Celebration $celebration): array
    {
        // A member event whose `contributions` capability is disabled must not
        // leak its pledge details through the celebration roll-up. We include
        // only member events that have contributions enabled — the same policy
        // applied to the account report.
        $enabledEventIds = $this->contributionEventIds(
            $celebration->events()->where('user_id', $celebration->user_id)->get()
        );

        $pledges = Pledge::query()
            ->whereIn('event_id', $enabledEventIds)
            ->with(['fulfillments', 'event:id,title'])
            ->get();

        $summary = $this->aggregate($pledges);
        $summary['event_count'] = $enabledEventIds->count();
        $summary['events'] = $this->eventBreakdown($pledges);

        return $summary;
    }

    /**
     * Ids of the events (from the given set) whose `contributions` capability is
     * enabled. Used so pledge roll-ups never include a disabled event's details.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, int>
     */
    private function contributionEventIds(Collection $events): Collection
    {
        return $events
            ->filter(fn (Event $event) => (bool) (($event->capabilities ?? [])['contributions'] ?? false))
            ->pluck('id')
            ->values();
    }

    /**
     * Aggregate a set of pledges.
     *
     * Semantics (deliberate and covered by PledgeSummaryTest):
     *
     *  - Counts (`total_pledges`, `counts`, `status_counts`) are
     *    currency-agnostic and INCLUDE cancelled pledges.
     *  - `currencies[]` holds every money figure. Cancelled pledges are
     *    excluded from pledged/fulfilled/outstanding, and each currency is kept
     *    in its own row — different currencies are never summed.
     *  - `by_method` is a per-(method, currency) reported-entry breakdown:
     *    `entries` counts recorded (non-reversed) entries and `amount_minor` is
     *    the reported amount for that method IN ONE CURRENCY (never summed across
     *    currencies). Cancelled pledges are excluded. Use `currencies[]` for the
     *    per-currency totals.
     *
     * @param  Collection<int, Pledge>  $pledges
     * @return array<string, mixed>
     */
    public function aggregate(Collection $pledges): array
    {
        $derivedCounts = [
            Pledge::DERIVED_OPEN => 0,
            Pledge::DERIVED_PARTIALLY_FULFILLED => 0,
            Pledge::DERIVED_FULFILLED => 0,
            Pledge::DERIVED_OVER_FULFILLED => 0,
            Pledge::DERIVED_CANCELLED => 0,
        ];
        $statusCounts = [
            Pledge::STATUS_ACTIVE => 0,
            Pledge::STATUS_CANCELLED => 0,
        ];
        $currencies = [];
        $methods = [];

        foreach ($pledges as $pledge) {
            $statusCounts[$pledge->status] = ($statusCounts[$pledge->status] ?? 0) + 1;
            $derivedCounts[$pledge->derivedStatus()] =
                ($derivedCounts[$pledge->derivedStatus()] ?? 0) + 1;

            // Cancelled pledges are retained in the counts and in history, but
            // they no longer represent money the client expects to give, so
            // they are excluded from pledged/fulfilled/outstanding totals.
            if ($pledge->isCancelled()) {
                continue;
            }

            $currency = (string) $pledge->currency;
            if (! isset($currencies[$currency])) {
                $currencies[$currency] = [
                    'currency' => $currency,
                    'pledges' => 0,
                    'pledged_minor' => 0,
                    'fulfilled_minor' => 0,
                    'outstanding_minor' => 0,
                ];
            }

            $pledged = (int) $pledge->pledged_amount_minor;
            $fulfilled = $pledge->fulfilledMinor();

            $currencies[$currency]['pledges']++;
            $currencies[$currency]['pledged_minor'] += $pledged;
            $currencies[$currency]['fulfilled_minor'] += $fulfilled;
            $currencies[$currency]['outstanding_minor'] += max(0, $pledged - $fulfilled);

            $fulfillments = $pledge->relationLoaded('fulfillments')
                ? $pledge->getRelation('fulfillments')
                : $pledge->fulfillments()->get();

            foreach ($fulfillments as $fulfillment) {
                $method = $fulfillment->method ?? 'unspecified';

                // Group by method AND currency so a reported amount is never
                // summed across different currencies.
                $key = $method.'|'.$currency;

                if (! isset($methods[$key])) {
                    $methods[$key] = ['method' => $method, 'currency' => $currency, 'entries' => 0, 'amount_minor' => 0];
                }

                if ($fulfillment->isReversal()) {
                    $methods[$key]['amount_minor'] -= (int) $fulfillment->amount_minor;
                } elseif ($fulfillment->status === PledgeFulfillment::STATUS_RECORDED) {
                    $methods[$key]['entries']++;
                    $methods[$key]['amount_minor'] += (int) $fulfillment->amount_minor;
                }
            }
        }

        ksort($currencies);
        ksort($methods);

        return [
            'total_pledges' => $pledges->count(),
            'counts' => $derivedCounts,
            'status_counts' => $statusCounts,
            'by_method' => array_values($methods),
            'currencies' => array_values($currencies),
        ];
    }

    /**
     * Per-event aggregation for the account/celebration reports.
     *
     * @param  Collection<int, Pledge>  $pledges
     * @return list<array<string, mixed>>
     */
    private function eventBreakdown(Collection $pledges): array
    {
        return $pledges
            ->groupBy('event_id')
            ->map(function (Collection $group) {
                $first = $group->first();
                $summary = $this->aggregate($group);

                return [
                    'event_id' => (int) $first->event_id,
                    'title' => $first->relationLoaded('event') ? $first->event?->title : null,
                    'total_pledges' => $summary['total_pledges'],
                    'counts' => $summary['counts'],
                    'currencies' => $summary['currencies'],
                ];
            })
            ->sortBy('event_id')
            ->values()
            ->all();
    }

    private function resolveGuest(Event $event, mixed $guestId): ?Guest
    {
        if ($guestId === null || $guestId === '') {
            return null;
        }

        $guest = $event->guests()->find((int) $guestId);

        if (! $guest) {
            throw ValidationException::withMessages([
                'guest_id' => ['The selected guest does not belong to this event.'],
            ]);
        }

        return $guest;
    }

    private function resolveContact(int $accountId, mixed $contactId): ?Contact
    {
        if ($contactId === null || $contactId === '') {
            return null;
        }

        $contact = Contact::query()
            ->where('user_id', $accountId)
            ->find((int) $contactId);

        if (! $contact) {
            throw ValidationException::withMessages([
                'contact_id' => ['The selected contact does not belong to this account.'],
            ]);
        }

        return $contact;
    }

    private function resolveSnapshotName(array $data, ?Guest $guest, ?Contact $contact): string
    {
        $name = $this->cleanOptional($data['pledger_name'] ?? null)
            ?? $this->cleanOptional($guest?->name)
            ?? $this->cleanOptional($contact?->name);

        if ($name === null) {
            throw ValidationException::withMessages([
                'pledger_name' => ['A pledger name is required.'],
            ]);
        }

        return $name;
    }

    private function normalizeCurrency(mixed $currency): string
    {
        $value = is_string($currency) ? strtoupper(trim($currency)) : '';

        if (! preg_match(self::CURRENCY_PATTERN, $value)) {
            throw ValidationException::withMessages([
                'currency' => ['The currency must be a three-letter ISO-4217 code (e.g. USD, TZS).'],
            ]);
        }

        return $value;
    }

    private function normalizePledgedAmount(mixed $amount): int
    {
        if (! is_int($amount) && ! (is_string($amount) && preg_match('/^\d+$/', $amount))) {
            throw ValidationException::withMessages([
                'pledged_amount_minor' => ['The pledged amount must be a whole number of minor units.'],
            ]);
        }

        $value = (int) $amount;

        if ($value < 0) {
            throw ValidationException::withMessages([
                'pledged_amount_minor' => ['The pledged amount cannot be negative.'],
            ]);
        }

        return $value;
    }

    private function normalizeFulfillmentAmount(mixed $amount): int
    {
        if (! is_int($amount) && ! (is_string($amount) && preg_match('/^\d+$/', $amount))) {
            throw ValidationException::withMessages([
                'amount_minor' => ['The fulfillment amount must be a whole number of minor units.'],
            ]);
        }

        $value = (int) $amount;

        if ($value < 1) {
            throw ValidationException::withMessages([
                'amount_minor' => ['The fulfillment amount must be greater than zero.'],
            ]);
        }

        return $value;
    }

    private function normalizeMethod(mixed $method): ?string
    {
        $value = $this->cleanOptional(is_string($method) ? $method : null);

        if ($value === null) {
            return null;
        }

        if (! in_array($value, PledgeFulfillment::METHODS, true)) {
            throw ValidationException::withMessages([
                'method' => ['The fulfillment method is not supported.'],
            ]);
        }

        return $value;
    }

    private function normalizeSource(mixed $source): string
    {
        $value = $this->cleanOptional(is_string($source) ? $source : null);

        if ($value !== null && ! in_array($value, [Pledge::SOURCE_MANUAL, Pledge::SOURCE_IMPORT], true)) {
            throw ValidationException::withMessages([
                'source' => ['The source must be either manual or import.'],
            ]);
        }

        return $value ?? Pledge::SOURCE_MANUAL;
    }

    private function hasFulfillments(Pledge $pledge): bool
    {
        return $pledge->fulfillments()->exists();
    }

    private function cleanOptional(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
