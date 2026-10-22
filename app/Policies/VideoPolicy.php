<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

/**
 * Single-workspace app: records without an owner belong to every dashboard
 * user; owned records only to their owner.
 */
class VideoPolicy
{
    public function view(User $user, Video $model): bool
    {
        return $model->user_id === null || $model->user_id === $user->id;
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
