<?php

namespace Tests\Support;

use App\Enums\Platform;
use App\Models\AiContent;
use App\Models\PostingRule;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Media\MediaToolkit;
use Illuminate\Support\Facades\Storage;

trait BuildsFixtures
{
    protected FakeMediaToolkit $media;

    protected function fakeInfrastructure(): void
    {
        Storage::fake('videos');
        Storage::fake('local');
        $this->media = new FakeMediaToolkit;
        $this->app->instance(MediaToolkit::class, $this->media);
    }

    protected function readyVideo(array $attributes = [], bool $withFile = true): Video
    {
        $video = Video::factory()->create($attributes);
        if ($withFile) {
            Storage::disk('videos')->put($video->storage_key, str_repeat('v', $video->size_bytes > 2_000_000 ? 1024 : $video->size_bytes));
            $video->update(['size_bytes' => Storage::disk('videos')->size($video->storage_key)]);
        }

        return $video;
    }

    protected function contentFor(Video $video, Platform $platform, array $attributes = []): AiContent
    {
        return AiContent::factory()->create(array_merge(['video_id' => $video->id, 'platform' => $platform], $attributes));
    }

    protected function account(Platform $platform, array $attributes = [], array $rule = []): SocialAccount
    {
        $factory = SocialAccount::factory();
        $factory = match ($platform) {
            Platform::Facebook => $factory->facebook(),
            Platform::Instagram => $factory->instagram(),
            Platform::YouTube => $factory->youtube(),
        };
        $account = $factory->create($attributes);
        PostingRule::factory()->create(array_merge(['social_account_id' => $account->id, 'platform' => $platform], $rule));

        return $account->load('postingRule');
    }

    /**
     * @return array{0: ScheduledPost, 1: Video, 2: SocialAccount}
     */
    protected function duePost(Platform $platform, array $postAttributes = [], array $accountAttributes = []): array
    {
        $video = $this->readyVideo();
        $this->contentFor($video, $platform);
        $account = $this->account($platform, $accountAttributes);
        $post = ScheduledPost::factory()->create(array_merge([
            'video_id' => $video->id,
            'social_account_id' => $account->id,
            'platform' => $platform,
        ], $postAttributes));

        return [$post, $video, $account];
    }
}
