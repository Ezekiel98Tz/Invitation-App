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
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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

    public function getQrCodeDataAttribute(): string
    {
        $png = QrCode::format('png')->size(300)->generate($this->invite_url);

        return base64_encode($png);
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

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
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
