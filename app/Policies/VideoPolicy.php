<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

/**
 * A record is available to the users of its workspace only. Queries are
 * already scoped to the workspace; this is the second line of defence.
 */
class VideoPolicy
{
    public function view(User $user, Video $model): bool
    {
        return $model->workspace_id !== null && $model->workspace_id === $user->workspace_id;
    }

    public function update(User $user, Video $model): bool
    {
        return $this->view($user, $model);
    }

    public function delete(User $user, Video $model): bool
    {
        return $this->view($user, $model);
    }
}
