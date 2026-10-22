<?php

namespace App\Policies;

use App\Models\ScheduledPost;
use App\Models\User;

class ScheduledPostPolicy
{
    public function view(User $user, ScheduledPost $post): bool
    {
        return (new SocialAccountPolicy)->view($user, $post->socialAccount);
    }

    public function update(User $user, ScheduledPost $post): bool
    {
        return $this->view($user, $post);
    }
}
