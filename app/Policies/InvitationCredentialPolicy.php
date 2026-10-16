<?php

namespace App\Policies;

use App\Models\InvitationCredential;
use App\Models\User;

class InvitationCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, InvitationCredential $credential): bool
    {
        $eventUserId = $credential->relationLoaded('event')
            ? $credential->event?->user_id
            : $credential->event()->value('user_id');

        return $eventUserId === $user->id
            || ($user->isStaff() && $user->owner_id !== null && $eventUserId === $user->owner_id);
    }

    public function update(User $user, InvitationCredential $credential): bool
    {
        return $this->view($user, $credential);
    }

    public function delete(User $user, InvitationCredential $credential): bool
    {
        return $this->view($user, $credential);
    }
}
