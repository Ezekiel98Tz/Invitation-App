<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvitationCredential extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ISSUED = 'issued';

    public const TYPE_SHORT_CODE = 'short_code';

    protected $fillable = [
        'guest_id',
        'event_id',
        'type',
        'status',
        'code_hash',
        'code_encrypted',
        'replaces_id',
        'active_key',
        'issued_at',
        'activated_at',
        'revoked_at',
        'expires_at',
        'revoke_reason',
        'meta',
    ];

    /**
     * Never serialize the secret material or the uniqueness helper.
     *
     * @var list<string>
     */
    protected $hidden = [
        'code_hash',
        'code_encrypted',
        'active_key',
    ];

    protected function casts(): array
    {
        return [
            'code_encrypted' => 'encrypted',
            'meta' => 'array',
            'issued_at' => 'datetime',
            'activated_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
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

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class, 'credential_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Access-controlled reveal of the (decrypted) short code. Callers must be
     * authorization-gated and must never log, export or persist the result.
     */
    public function revealSecret(): ?string
    {
        return $this->code_encrypted;
    }
}
