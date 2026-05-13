<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'event_start',
        'timezone',
        'venue',
        'capacity',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'event_start' => 'datetime',
            'settings' => 'array',
            'capacity' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }

    public function getResponseRateAttribute(): float
    {
        $total = $this->guests()->count();

        if ($total === 0) {
            return 0.0;
        }

        $responded = $this->guests()
            ->whereIn('status', ['accepted', 'declined'])
            ->count();

        return round(($responded / $total) * 100, 2);
    }

    public function acceptGuest(Guest $guest, int $attendingCount = 1): bool
    {
        if ($guest->event_id !== $this->id) {
            return false;
        }

        if ($this->isAtCapacity($attendingCount)) {
            return false;
        }

        $guest->status = 'accepted';
        $guest->save();

        $guest->rsvp()->updateOrCreate(
            ['guest_id' => $guest->id],
            ['attending_count' => $attendingCount]
        );

        return true;
    }

    public function sendInvites(): int
    {
        return $this->guests()
            ->unsent()
            ->get()
            ->each(fn (Guest $guest) => dispatch(new \App\Jobs\SendInvitationJob($guest)))
            ->count();
    }

    public function isAtCapacity(int $additionalAttendees = 0): bool
    {
        if ($this->capacity === null) {
            return false;
        }

        $accepted = $this->guests()
            ->where('status', 'accepted')
            ->with('rsvp')
            ->get()
            ->sum(fn (Guest $guest) => $guest->rsvp?->attending_count ?? 1);

        return ($accepted + $additionalAttendees) > $this->capacity;
    }
}
