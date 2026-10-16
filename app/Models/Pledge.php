<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recorded pledge (information only — never money movement).
 *
 * Outstanding pledges never block invitations, RSVPs, credentials or check-in.
 * Balances are derived from the fulfillment ledger, never stored, so the
 * celebration/account totals are aggregations with no duplicated records.
 *
 * Amounts are integer minor units in `currency` (ISO-4217). A pledge and all of
 * its fulfillments share that single currency.
 *
 * A pledge created by a spreadsheet import carries a nullable `import_batch_id`
 * lineage pointer back to its `PledgeImportBatch` (see `importBatch()`). Manual
 * pledges leave it null. The pointer is informational only and clearing it
 * (batch deleted) never affects the pledge itself.
 */
class Pledge extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_IMPORT = 'import';

    public const DERIVED_OPEN = 'open';
    public const DERIVED_PARTIALLY_FULFILLED = 'partially_fulfilled';
    public const DERIVED_FULFILLED = 'fulfilled';
    public const DERIVED_OVER_FULFILLED = 'over_fulfilled';
    public const DERIVED_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'event_id',
        'guest_id',
        'contact_id',
        'pledger_name',
        'pledger_email',
        'pledger_phone',
        'currency',
        'pledged_amount_minor',
        'note',
        'source',
        'status',
        'created_by',
        'import_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'pledged_amount_minor' => 'integer',
            'import_batch_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(PledgeFulfillment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The import batch this pledge was created from, if any. Null for pledges
     * recorded manually.
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(PledgeImportBatch::class, 'import_batch_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeForEvent(Builder $query, int $eventId): Builder
    {
        return $query->where('event_id', $eventId);
    }

    public function scopeForAccount(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Net reported fulfillment in minor units.
     *
     * A recorded entry adds its amount; a compensating (reversal) entry — one
     * that carries `reverses_fulfillment_id` — subtracts its amount. The
     * original is never edited or deleted, so history is fully preserved.
     *
     * When the `fulfillments` relation is already loaded this reads it directly,
     * avoiding an extra query per pledge in aggregates.
     */
    public function fulfilledMinor(): int
    {
        $fulfillments = $this->relationLoaded('fulfillments')
            ? $this->getRelation('fulfillments')
            : $this->fulfillments()->get();

        $total = 0;

        foreach ($fulfillments as $fulfillment) {
            if ($fulfillment->reverses_fulfillment_id !== null) {
                $total -= (int) $fulfillment->amount_minor;

                continue;
            }

            if ($fulfillment->status === PledgeFulfillment::STATUS_RECORDED) {
                $total += (int) $fulfillment->amount_minor;
            }
        }

        return $total;
    }

    /**
     * Remaining amount in minor units. Over-fulfillment never produces a
     * negative outstanding balance; it is surfaced through `derivedStatus()`
     * instead.
     */
    public function outstandingMinor(): int
    {
        return max(0, (int) $this->pledged_amount_minor - $this->fulfilledMinor());
    }

    /**
     * Derived lifecycle state: open|partially_fulfilled|fulfilled|over_fulfilled|cancelled.
     */
    public function derivedStatus(): string
    {
        if ($this->isCancelled()) {
            return self::DERIVED_CANCELLED;
        }

        $pledged = (int) $this->pledged_amount_minor;
        $fulfilled = $this->fulfilledMinor();

        if ($fulfilled > $pledged) {
            return self::DERIVED_OVER_FULFILLED;
        }

        if ($fulfilled === $pledged) {
            return self::DERIVED_FULFILLED;
        }

        if ($fulfilled > 0) {
            return self::DERIVED_PARTIALLY_FULFILLED;
        }

        return self::DERIVED_OPEN;
    }
}
