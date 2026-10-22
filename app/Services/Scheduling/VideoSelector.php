<?php

namespace App\Services\Scheduling;

use App\Enums\PostStatus;
use App\Models\PostingRule;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Media\PlatformRequirements;
use App\Services\Publishing\ContentResolver;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chooses the next video to schedule on an account.
 */
class VideoSelector
{
    public function __construct(
        private readonly ContentResolver $contents,
        private readonly PlatformRequirements $requirements,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array<int, int>  $exclude  video IDs already chosen in this run
     * @return array{0: Video, 1: int}|null [video, reuse_sequence]
     */
    public function next(SocialAccount $account, PostingRule $rule, array $exclude = []): ?array
    {
        $fresh = $this->candidates($account, $rule, $exclude)
            ->whereDoesntHave('scheduledPosts', fn (Builder $q) => $q->where('social_account_id', $account->id));

        if (! $this->settings->bool('publishing.cross_post_all')) {
            // Each video goes to one destination only.
            $fresh->whereDoesntHave('scheduledPosts', fn (Builder $q) => $q->where('status', '!=', PostStatus::Cancelled->value));
        }

        if ($video = $this->firstEligible($fresh, $account)) {
            return [$video, 0];
        }

        // Nothing new left: reuse videos only when explicitly allowed.
        $reuse = $this->candidates($account, $rule, $exclude)
            ->where(fn (Builder $q) => $rule->allow_reuse ? $q : $q->where('allow_reuse', true))
            ->whereHas('scheduledPosts', fn (Builder $q) => $q->where('social_account_id', $account->id))
            ->whereDoesntHave('scheduledPosts', fn (Builder $q) => $q->where('social_account_id', $account->id)
                ->whereIn('status', [PostStatus::Scheduled->value, PostStatus::Publishing->value, PostStatus::Processing->value, PostStatus::Retrying->value]))
            ->reorder()
            ->orderBy(ScheduledPost::select('scheduled_at')->whereColumn('video_id', 'videos.id')
                ->where('social_account_id', $account->id)->latest('scheduled_at')->limit(1));

        if ($video = $this->firstEligible($reuse, $account)) {
            $sequence = (int) ScheduledPost::where('video_id', $video->id)->where('social_account_id', $account->id)->max('reuse_sequence') + 1;

            return [$video, $sequence];
        }

        return null;
    }

    /**
     * @param  array<int, int>  $exclude
     */
    private function candidates(SocialAccount $account, PostingRule $rule, array $exclude): Builder
    {
        $query = Video::query()->ready()->whereNotNull('storage_key')->whereNull('storage_deleted_at')
            ->whereHas('aiContents', fn (Builder $q) => $q->where('platform', $account->platform->value)->where('is_current', true))
            ->when($exclude, fn (Builder $q) => $q->whereNotIn('id', $exclude));

        if (! empty($rule->folder_filter)) {
            $query->where(function (Builder $q) use ($rule) {
                foreach ($rule->folder_filter as $folder) {
                    $q->orWhere('folder', $folder)->orWhere('folder', 'like', str_replace(['%', '_'], ['\%', '\_'], $folder).'/%');
                }
            });
        }

        return match ($rule->selection_order) {
            PostingRule::ORDER_NEWEST => $query->orderByDesc('uploaded_at')->orderByDesc('id'),
            PostingRule::ORDER_RANDOM => $query->inRandomOrder(),
            default => $query->orderBy('uploaded_at')->orderBy('id'),
        };
    }

    private function firstEligible(Builder $query, SocialAccount $account): ?Video
    {
        foreach ($query->limit(200)->cursor() as $video) {
            if ($this->requirements->problems($video, $account->platform) !== []) {
                continue;
            }
            if ($this->contents->publishable($video, $account->platform) === null) {
                continue;
            }

            return $video;
        }

        return null;
    }
}
