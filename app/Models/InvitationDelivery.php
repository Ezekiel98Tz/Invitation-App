<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitationDelivery extends Model
{
    use HasFactory;

    protected $fillable = [
        'guest_id',
        'kind',
        'channel',
        'status',
        'provider_message_id',
        'error',
        'meta',
        'sent_at',
        'delivered_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /**
     * Open the delivery row for one logical channel of a guest operation.
     *
     * When `$reuseExisting` is true (a queue retry of the same job), the latest
     * existing row for this guest/kind/channel is reused so retries do not
     * create duplicate records. A fresh operation (a deliberate send/resend or
     * reminder) passes false and creates a new row.
     */
    public static function openFor(int $guestId, string $kind, string $channel, bool $reuseExisting): self
    {
        if ($reuseExisting) {
            $existing = static::query()
                ->where('guest_id', $guestId)
                ->where('kind', $kind)
                ->where('channel', $channel)
                ->latest('id')
                ->first();

            // A Delivery Lab test row is never treated as this operation's row.
            if ($existing && ! (($existing->meta['lab']['test'] ?? false) === true)) {
                return $existing;
            }
        }

        return static::create([
            'guest_id' => $guestId,
            'kind' => $kind,
            'channel' => $channel,
            'status' => 'queued',
        ]);
    }

    /**
     * A delivery is complete once the provider has accepted it (sent) or
     * confirmed it (delivered). Completed deliveries must never be re-sent.
     */
    public function isCompleted(): bool
    {
        return in_array($this->status, ['sent', 'delivered'], true);
    }

    /**
     * Record provider acceptance (an accepted/submitted request). This is NOT
     * confirmed delivery — that only comes from a provider status callback.
     */
    public function recordAccepted(?string $providerMessageId, ?int $httpStatus = null, array $extra = []): void
    {
        $meta = (array) ($this->meta ?? []);
        $provider = (array) ($meta['provider'] ?? []);
        $provider['accepted'] = true;
        $provider['has_reference'] = $providerMessageId !== null;
        if ($httpStatus !== null) {
            $provider['http_status'] = $httpStatus;
        }
        $meta['provider'] = $provider;
        $meta = array_replace_recursive($meta, $extra);

        $this->fill([
            'status' => 'sent',
            'sent_at' => $this->sent_at ?? now(),
            'provider_message_id' => $providerMessageId ?? $this->provider_message_id,
            'error' => null,
            'meta' => $meta,
        ])->save();
    }

    /**
     * Record a sanitized provider failure. Never store the raw response body,
     * headers, credentials or message contents — only a generic reason.
     */
    public function recordFailure(string $reason, string $message, ?int $httpStatus = null, array $extra = []): void
    {
        $meta = (array) ($this->meta ?? []);
        $provider = (array) ($meta['provider'] ?? []);
        $provider['accepted'] = false;
        $provider['reason'] = $reason;
        if ($httpStatus !== null) {
            $provider['http_status'] = $httpStatus;
        }
        $meta['provider'] = $provider;
        $meta = array_replace_recursive($meta, $extra);

        $this->fill([
            'status' => 'failed',
            'failed_at' => $this->failed_at ?? now(),
            'error' => $message,
            'meta' => $meta,
        ])->save();
    }
}
