<?php

namespace App\Policies;

use App\Models\Guest;
use App\Models\User;

class GuestPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Guest $guest): bool
    {
        $eventUserId = $guest->relationLoaded('event')
            ? $guest->event?->user_id
            : $guest->event()->value('user_id');

        return $eventUserId === $user->id
            || ($user->isStaff() && $user->owner_id !== null && $eventUserId === $user->owner_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Guest $guest): bool
    {
        return $this->view($user, $guest);
    }

    public function delete(User $user, Guest $guest): bool
    {
        return $this->view($user, $guest);
    }

    public function restore(User $user, Guest $guest): bool
    {
        return $this->view($user, $guest);
    }

    public function forceDelete(User $user, Guest $guest): bool
    {
        return $this->view($user, $guest);
    }
}
