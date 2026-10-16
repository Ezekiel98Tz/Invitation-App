<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attendance extends Model
{
    public const STATUS_VALID = 'valid';
    public const STATUS_REVERSED = 'reversed';

    public const METHOD_QR = 'qr';
    public const METHOD_CODE = 'code';
    public const METHOD_MANUAL = 'manual';
    public const METHOD_OVERRIDE = 'override';

    protected $fillable = [
        'event_id',
        'guest_id',
        'credential_id',
        'rsvp_id',
        'checked_in_at',
        'checked_in_by',
        'method',
        'party_size',
        'status',
        'note',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
        'active_key',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'active_key',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(InvitationCredential::class, 'credential_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_VALID;
    }
}
