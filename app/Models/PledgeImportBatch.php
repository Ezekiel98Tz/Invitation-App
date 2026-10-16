<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pledge import batch container.
 *
 * Tracks an uploaded pledge spreadsheet and its processing state. This model
 * holds no pledge rows itself; pledge records created from an import reference
 * the batch through `Pledge` -> event/account scope.
 */
class PledgeImportBatch extends Model
{
    use HasFactory;

    public const STATUS_UPLOADED = 'uploaded';
    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'event_id',
        'original_filename',
        'status',
        'columns',
        'mapping',
        'summary',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'mapping' => 'array',
            'summary' => 'array',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
