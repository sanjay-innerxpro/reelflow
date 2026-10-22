<?php

namespace App\Policies;

use App\Models\SyncDevice;
use App\Models\User;

/**
 * Single-workspace app: records without an owner belong to every dashboard
 * user; owned records only to their owner.
 */
class SyncDevicePolicy
{
    public function view(User $user, SyncDevice $model): bool
    {
        return $model->user_id === null || $model->user_id === $user->id;
    }

    public function update(User $user, SyncDevice $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, SyncDevice $model): bool
    {
        return $this->view($user, $model);
    }
}
