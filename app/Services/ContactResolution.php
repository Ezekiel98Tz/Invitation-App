<?php

namespace App\Services;

use App\Models\Contact;

/**
 * Outcome of resolving an event-specific guest to a reusable account contact.
 *
 * `resolved` — an existing contact was matched.
 * `created`  — no identifier matched, so a new contact was created.
 * `conflict` — email and phone matched two different contacts; nothing was
 *              linked and no contact was created.
 */
final class ContactResolution
{
    public const RESOLVED = 'resolved';
    public const CREATED = 'created';
    public const CONFLICT = 'conflict';

    /**
     * @param  list<int>  $conflictContactIds
     */
    private function __construct(
        public readonly ?Contact $contact,
        public readonly string $status,
        public readonly ?string $emailKey,
        public readonly ?string $phoneKey,
        public readonly array $conflictContactIds = [],
    ) {}

    public static function resolved(Contact $contact, ?string $emailKey, ?string $phoneKey): self
    {
        return new self($contact, self::RESOLVED, $emailKey, $phoneKey);
    }

    public static function created(Contact $contact, ?string $emailKey, ?string $phoneKey): self
    {
        return new self($contact, self::CREATED, $emailKey, $phoneKey);
    }

    /**
     * @param  list<int>  $conflictContactIds
     */
    public static function conflict(?string $emailKey, ?string $phoneKey, array $conflictContactIds): self
    {
        return new self(null, self::CONFLICT, $emailKey, $phoneKey, array_values($conflictContactIds));
    }

    public function isConflict(): bool
    {
        return $this->status === self::CONFLICT;
    }

    public function contactId(): ?int
    {
        return $this->contact?->id;
    }
}
