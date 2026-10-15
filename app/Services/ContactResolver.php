<?php

namespace App\Services;

use App\Models\Contact;
use App\Support\ContactNormalizer;

/**
 * Resolves an event-specific guest to a reusable, account-scoped contact.
 *
 * Matching is scoped to a single account (`user_id`) — the caller passes the
 * event owner's account id, never the acting (possibly staff) user's id — so a
 * contact can never cross an account boundary.
 *
 * Order: normalized email first, then normalized phone. If email and phone
 * match two different contacts, a conflict is reported and nothing is linked or
 * created. Name is never used for matching, and a guest with no usable
 * identifier receives its own contact rather than being merged with anyone.
 *
 * This service only reads and creates contacts. It never edits or merges
 * existing contacts, and it never touches guest records.
 */
class ContactResolver
{
    /**
     * @param  string  $name  The guest's name, copied onto a newly created contact.
     */
    public function resolve(int $accountId, string $name, ?string $email, ?string $phone): ContactResolution
    {
        $emailKey = ContactNormalizer::email($email);
        $phoneKey = ContactNormalizer::phone($phone);

        $byEmail = $emailKey !== null ? $this->findByEmailKey($accountId, $emailKey) : null;
        $byPhone = $phoneKey !== null ? $this->findByPhoneKey($accountId, $phoneKey) : null;

        if ($byEmail !== null && $byPhone !== null && $byEmail->id !== $byPhone->id) {
            return ContactResolution::conflict($emailKey, $phoneKey, [(int) $byEmail->id, (int) $byPhone->id]);
        }

        $contact = $byEmail ?? $byPhone;

        if ($contact !== null) {
            return ContactResolution::resolved($contact, $emailKey, $phoneKey);
        }

        return ContactResolution::created(
            $this->create($accountId, $name, $email, $phone, $emailKey, $phoneKey),
            $emailKey,
            $phoneKey,
        );
    }

    /**
     * Look up an account-scoped contact by email only (used for update conflict
     * detection). Returns null when the email is unusable.
     */
    public function findContactByEmail(int $accountId, ?string $email): ?Contact
    {
        $key = ContactNormalizer::email($email);

        return $key !== null ? $this->findByEmailKey($accountId, $key) : null;
    }

    /**
     * Look up an account-scoped contact by phone only (used for update conflict
     * detection). Returns null when the phone is unusable.
     */
    public function findContactByPhone(int $accountId, ?string $phone): ?Contact
    {
        $key = ContactNormalizer::phone($phone);

        return $key !== null ? $this->findByPhoneKey($accountId, $key) : null;
    }

    private function findByEmailKey(int $accountId, string $key): ?Contact
    {
        return Contact::query()
            ->where('user_id', $accountId)
            ->where('normalized_email', $key)
            ->first();
    }

    private function findByPhoneKey(int $accountId, string $key): ?Contact
    {
        return Contact::query()
            ->where('user_id', $accountId)
            ->where('normalized_phone', $key)
            ->first();
    }

    private function create(
        int $accountId,
        string $name,
        ?string $email,
        ?string $phone,
        ?string $emailKey,
        ?string $phoneKey,
    ): Contact {
        // user_id is intentionally not mass-assignable; set it explicitly in
        // trusted server-side code so it can never be supplied by a client.
        $contact = new Contact();
        $contact->user_id = $accountId;
        $contact->name = $name;
        $contact->email = $this->cleanOptional($email);
        $contact->phone = $this->cleanOptional($phone);
        $contact->normalized_email = $emailKey;
        $contact->normalized_phone = $phoneKey;
        $contact->save();

        return $contact;
    }

    private function cleanOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
