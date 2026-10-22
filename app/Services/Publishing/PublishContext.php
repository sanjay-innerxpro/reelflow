<?php

namespace App\Services\Publishing;

use App\Models\AiContent;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\Video;

final class PublishContext
{
    public function __construct(
        public readonly ScheduledPost $post,
        public readonly SocialAccount $account,
        public readonly Video $video,
        public readonly AiContent $content,
        public readonly string $accessToken,
    ) {}
}
