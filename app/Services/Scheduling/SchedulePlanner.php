<?php

namespace App\Services\Scheduling;

use App\Enums\PostStatus;
use App\Models\PostingRule;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Support\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Fills upcoming posting slots for every enabled rule. Runs under a
 * per-account lock so overlapping runs cannot double-book a slot.
 */
class SchedulePlanner
{
    public function __construct(
        private readonly SlotCalculator $slots,
        private readonly VideoSelector $selector,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return int number of posts created
     */
    public function planAll(?CarbonImmutable $now = null): int
    {
        $created = 0;
        $accounts = SocialAccount::publishable()
            ->whereHas('postingRule', fn ($q) => $q->where('enabled', true))
            ->with('postingRule')
            ->get();

        foreach ($accounts as $account) {
            $created += $this->planAccount($account, $now);
        }

        return $created;
    }

    public function planAccount(SocialAccount $account, ?CarbonImmutable $now = null): int
    {
        $rule = $account->postingRule;
        if (! $rule || ! $rule->enabled || ! $account->isPublishable()) {
            return 0;
        }

        $lock = Cache::lock('planner:account:'.$account->id, 120);
        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->fill($account, $rule, $now ?? CarbonImmutable::now());
        } finally {
            $lock->release();
        }
    }

    private function fill(SocialAccount $account, PostingRule $rule, CarbonImmutable $now): int
    {
        $created = 0;
        $chosen = [];
        $earliest = $now->addMinutes(2);
        $today = $now->setTimezone($rule->timezone)->startOfDay();

        for ($d = 0; $d <= config('reelflow.scheduling.planning_horizon_days'); $d++) {
            $day = $today->addDays($d);
            $dayStart = $day->utc();
            $dayEnd = $day->endOfDay()->utc();

            $existing = ScheduledPost::where('social_account_id', $account->id)
                ->whereIn('status', PostStatus::activeValues())
                ->whereBetween('scheduled_at', [$dayStart, $dayEnd])
                ->pluck('scheduled_at')
                ->map(fn ($t) => CarbonImmutable::parse($t))
                ->all();

            $slots = $this->slots->slotsForDay($rule, $day);
            $remaining = max(0, count($slots) - count($existing));

            foreach ($slots as $slot) {
                if ($remaining <= 0) {
                    break;
                }
                if ($slot->lt($earliest) || $this->tooClose($slot, $existing, $rule->min_interval_minutes)) {
                    continue;
                }
                if (! $this->withinPeriodLimit($account, $rule, $slot)) {
                    continue;
                }

                $pick = $this->selector->next($account, $rule, $chosen);
                if (! $pick) {
                    return $created; // nothing eligible to post
                }
                [$video, $sequence] = $pick;

                try {
                    $post = ScheduledPost::create([
                        'video_id' => $video->id,
                        'social_account_id' => $account->id,
                        'ai_content_id' => $video->currentContent($account->platform)?->id,
                        'platform' => $account->platform,
                        'reuse_sequence' => $sequence,
                        'scheduled_at' => $slot,
                        'status' => PostStatus::Scheduled,
                        'max_attempts' => $rule->max_attempts,
                    ]);
                } catch (QueryException $e) {
                    // Unique (video, account, sequence): another run took it.
                    $chosen[] = $video->id;

                    continue;
                }

                $this->activity->log('post.scheduled', $post, sprintf('Auto-scheduled %s on %s', $video->original_filename, $account->name));
                $chosen[] = $video->id;
                $existing[] = $slot;
                $remaining--;
                $created++;
            }
        }

        return $created;
    }

    /**
     * @param  array<int, CarbonImmutable>  $existing
     */
    private function tooClose(CarbonImmutable $slot, array $existing, int $minGap): bool
    {
        foreach ($existing as $time) {
            if (abs($time->diffInMinutes($slot)) < max(1, $minGap)) {
                return true;
            }
        }

        return false;
    }

    private function withinPeriodLimit(SocialAccount $account, PostingRule $rule, CarbonImmutable $slot): bool
    {
        if (! $rule->max_posts_per_period || ! $rule->period_hours) {
            return true;
        }

        // Rolling window: the period ending at the slot and the period
        // starting at it must both stay under the limit.
        foreach ([[$slot->subHours($rule->period_hours), $slot], [$slot, $slot->addHours($rule->period_hours)]] as [$from, $to]) {
            $count = ScheduledPost::where('social_account_id', $account->id)
                ->whereIn('status', PostStatus::activeValues())
                ->where('scheduled_at', '>', $from)
                ->where('scheduled_at', '<', $to)
                ->count();
            if ($count >= $rule->max_posts_per_period) {
                return false;
            }
        }

        return true;
    }
}
