<?php

namespace App\Policies;

use App\Models\SyncDevice;
use App\Models\User;

/**
 * A record is available to the users of its workspace only. Queries are
 * already scoped to the workspace; this is the second line of defence.
 */
class SyncDevicePolicy
{
    public function view(User $user, SyncDevice $model): bool
    {
        return $model->workspace_id !== null && $model->workspace_id === $user->workspace_id;
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
