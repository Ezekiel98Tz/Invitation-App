<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reported pledge fulfillment (append-only).
 *
 * This is a record of what a client *reported*; it is not a payment and no
 * money moves. Multiple partial entries are allowed. Corrections are recorded
 * as a compensating entry (`reverses_fulfillment_id`) and never by editing or
 * deleting the original row.
 */
class PledgeFulfillment extends Model
{
    use HasFactory;

    public const STATUS_RECORDED = 'recorded';
    public const STATUS_REVERSED = 'reversed';

    public const METHOD_CASH = 'cash';
    public const METHOD_BANK_TRANSFER = 'bank_transfer';
    public const METHOD_MOBILE_MONEY = 'mobile_money';
    public const METHOD_CHEQUE = 'cheque';
    public const METHOD_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_BANK_TRANSFER,
        self::METHOD_MOBILE_MONEY,
        self::METHOD_CHEQUE,
        self::METHOD_OTHER,
    ];

    protected $fillable = [
        'pledge_id',
        'amount_minor',
        'method',
        'method_detail',
        'received_on',
        'reference',
        'note',
        'recorded_by',
        'status',
        'reverses_fulfillment_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_on' => 'date',
        ];
    }

    public function pledge(): BelongsTo
    {
        return $this->belongsTo(Pledge::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_fulfillment_id');
    }

    public function isReversal(): bool
    {
        return $this->reverses_fulfillment_id !== null;
    }

    /**
     * Currency is inherited from the pledge; a pledge and all of its
     * fulfillments always share one currency.
     */
    public function currency(): ?string
    {
        return $this->relationLoaded('pledge')
            ? $this->pledge?->currency
            : $this->pledge()->value('currency');
    }
}
