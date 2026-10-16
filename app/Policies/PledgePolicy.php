<?php

namespace App\Policies;

use App\Models\Pledge;
use App\Models\User;

/**
 * Mirrors ContactPolicy / EventPolicy: a pledge belongs to the account owner,
 * staff may view and edit their assigned owner's pledges, and list/detail access
 * is always scoped by the owner id (staff resolve to their `owner_id`).
 *
 * Pledges are information only, so recording/editing never depends on any
 * outstanding balance.
 */
class PledgePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Pledge $pledge): bool
    {
        return $pledge->user_id === $user->id
            || ($user->isStaff() && $user->owner_id !== null && $pledge->user_id === $user->owner_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Pledge $pledge): bool
    {
        return $this->view($user, $pledge);
    }

    public function delete(User $user, Pledge $pledge): bool
    {
        return $pledge->user_id === $user->id && $user->isAdmin();
    }

    public function restore(User $user, Pledge $pledge): bool
    {
        return $this->delete($user, $pledge);
    }

    public function forceDelete(User $user, Pledge $pledge): bool
    {
        return $this->delete($user, $pledge);
    }
}
