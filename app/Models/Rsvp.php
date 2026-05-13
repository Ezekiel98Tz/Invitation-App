<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rsvp extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'attending_count',
        'answers',
    ];

    protected function casts(): array
    {
        return [
            'attending_count' => 'integer',
            'answers' => 'array',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class)->with('event');
    }
}
