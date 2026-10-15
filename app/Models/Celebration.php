<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account-scoped container that groups related occasions (events).
 *
 * A celebration is a grouping only: it does not own or merge event-specific
 * data (date, venue, settings, invitation design, guests, RSVPs, deliveries).
 * Those remain on each event, so an event can be added to or removed from a
 * celebration without affecting its data.
 */
class Celebration extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
