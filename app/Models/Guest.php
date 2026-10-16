<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class Guest extends Model
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'event_id',
        'name',
        'email',
        'phone',
        'status',
    ];

    protected $guarded = [
        'invite_token',
    ];

    /**
     * Phase 3.1 compatibility measure: `contact_id` exists on the `guests`
     * table but contacts are not yet part of any workflow or API contract.
     * Hiding it keeps guest serialization and API responses identical to the
     * pre-contacts behaviour. Remove this once contacts are intentionally
     * exposed (a later phase).
     *
     * @var list<string>
     */
    protected $hidden = [
        'contact_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Guest $guest) {
            if (! $guest->invite_token) {
                $guest->invite_token = Str::random(32);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function rsvp(): HasOne
    {
        return $this->hasOne(Rsvp::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(InvitationDelivery::class);
    }

    public function getInviteUrlAttribute(): string
    {
        return route('invites.show', ['token' => $this->invite_token], absolute: false);
    }

    /**
     * Absolute invitation URL for external channels (email, SMS, WhatsApp) and
     * QR codes, resolved from the configured application URL. The relative
     * `invite_url` is retained for same-origin browser links.
     */
    public function getInviteUrlAbsoluteAttribute(): string
    {
        return route('invites.show', ['token' => $this->invite_token], absolute: true);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', 'accepted');
    }

    public function scopeDeclined(Builder $query): Builder
    {
        return $query->where('status', 'declined');
    }

    public function scopeUnsent(Builder $query): Builder
    {
        return $query->whereNull('sent_at');
    }

    public function scopeUnreminded(Builder $query): Builder
    {
        return $query->whereNull('reminded_at');
    }

    /**
     * Usable email destination, or null.
     *
     * A missing, empty or whitespace-only address is reported as null so the
     * mail channel is treated like the SMS/WhatsApp channels: an absent
     * destination means the channel simply does not apply, rather than a send
     * that silently no-ops and is recorded as accepted.
     */
    public function routeNotificationForMail(): ?string
    {
        $email = trim((string) $this->email);

        return $email === '' ? null : $email;
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    public function routeNotificationForWhatsApp(): ?string
    {
        return $this->phone;
    }
}
