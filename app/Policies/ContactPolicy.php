<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

/**
 * Mirrors the existing owner/staff model used by events and celebrations:
 * the account owner manages their own contacts, staff may view and edit their
 * assigned owner's contacts, and only the owner (admin) may delete a contact.
 */
class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $contact->user_id === $user->id
            || ($user->isStaff() && $user->owner_id !== null && $contact->user_id === $user->owner_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->view($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $contact->user_id === $user->id && $user->isAdmin();
    }

    public function restore(User $user, Contact $contact): bool
    {
        return $this->delete($user, $contact);
    }

    public function forceDelete(User $user, Contact $contact): bool
    {
        return $this->delete($user, $contact);
    }
}
