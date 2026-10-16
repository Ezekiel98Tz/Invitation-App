<?php

namespace App\Policies;

use App\Models\PledgeImportBatch;
use App\Models\User;

/**
 * Pledge import batches follow the same owner/staff rule as pledges and
 * contacts: the owner manages their own batches and staff operate within their
 * assigned owner's account. All access is scoped by the owner id.
 */
class PledgeImportBatchPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PledgeImportBatch $batch): bool
    {
        return $batch->user_id === $user->id
            || ($user->isStaff() && $user->owner_id !== null && $batch->user_id === $user->owner_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, PledgeImportBatch $batch): bool
    {
        return $this->view($user, $batch);
    }

    public function delete(User $user, PledgeImportBatch $batch): bool
    {
        return $batch->user_id === $user->id && $user->isAdmin();
    }

    public function restore(User $user, PledgeImportBatch $batch): bool
    {
        return $this->delete($user, $batch);
    }

    public function forceDelete(User $user, PledgeImportBatch $batch): bool
    {
        return $this->delete($user, $batch);
    }
}
