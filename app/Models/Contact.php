<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account-scoped reusable contact identity.
 *
 * A contact may be linked from several event guests (memberships) within the
 * same account. This phase introduces the identity container only: it does not
 * resolve, match, merge or synchronize contacts with guests, and the existing
 * guest name/email/phone fields remain the values used by current workflows.
 */
class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'normalized_email',
        'normalized_phone',
        'notes',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guests(): HasMany
    {
        return $this->hasMany(Guest::class);
    }
}
