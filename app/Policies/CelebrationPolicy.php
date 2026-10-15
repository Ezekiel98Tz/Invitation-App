<?php

namespace App\Policies;

use App\Models\Celebration;
use App\Models\User;

/**
 * Mirrors EventPolicy so celebrations follow the existing owner/staff model:
 * the owner manages their own celebrations, staff may view/update their
 * assigned owner's celebrations, and destructive actions stay with the owner.
 */
class CelebrationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Celebration $celebration): bool
    {
        return $celebration->user_id === $user->id
            || ($user->isStaff() && $user->owner_id !== null && $celebration->user_id === $user->owner_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Celebration $celebration): bool
    {
        return $this->view($user, $celebration);
    }

    public function delete(User $user, Celebration $celebration): bool
    {
        return $celebration->user_id === $user->id && $user->isAdmin();
    }

    public function restore(User $user, Celebration $celebration): bool
    {
        return $this->delete($user, $celebration);
    }

    public function forceDelete(User $user, Celebration $celebration): bool
    {
        return $this->delete($user, $celebration);
    }
}
