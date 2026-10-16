<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Attendance $attendance): bool
    {
        $eventUserId = $attendance->relationLoaded('event')
            ? $attendance->event?->user_id
            : $attendance->event()->value('user_id');

        return $eventUserId === $user->id
            || ($user->isStaff() && $user->owner_id !== null && $eventUserId === $user->owner_id);
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $this->view($user, $attendance);
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return $this->view($user, $attendance);
    }
}
