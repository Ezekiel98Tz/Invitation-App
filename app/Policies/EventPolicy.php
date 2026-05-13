<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Event $event): bool
    {
        return $event->user_id === $user->id
            || ($user->isStaff() && $user->owner_id !== null && $event->user_id === $user->owner_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Event $event): bool
    {
        return $this->view($user, $event);
    }

    public function delete(User $user, Event $event): bool
    {
        return $event->user_id === $user->id && $user->isAdmin();
    }

    public function restore(User $user, Event $event): bool
    {
        return $this->delete($user, $event);
    }

    public function forceDelete(User $user, Event $event): bool
    {
        return $this->delete($user, $event);
    }
}
