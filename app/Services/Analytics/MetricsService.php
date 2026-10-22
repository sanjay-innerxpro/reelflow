<?php

namespace App\Services\Analytics;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\PostMetric;
use App\Models\ScheduledPost;
use App\Services\Platforms\GoogleClient;
use App\Services\Platforms\MetaGraphClient;
use App\Services\Platforms\TokenManager;
use App\Services\Publishing\PublishException;
use Illuminate\Support\Facades\Log;

/**
 * Collects performance metrics where the official APIs and granted
 * permissions provide them. Missing metrics are stored as NULL, never 0.
 */
class MetricsService
{
    /** Instagram media insights → our columns. */
    private const IG_METRICS = [
        'views' => 'views', 'reach' => 'reach', 'likes' => 'likes', 'comments' => 'comments',
        'shares' => 'shares', 'saved' => 'saves', 'ig_reels_avg_watch_time' => 'avg_watch_seconds',
        'ig_reels_video_view_total_time' => 'total_watch_seconds',
    ];

    /** Facebook Reels video insights → our columns. */
    private const FB_METRICS = [
        'blue_reels_play_count' => 'views', 'post_impressions_unique' => 'reach',
        'post_video_avg_time_watched' => 'avg_watch_seconds', 'post_video_view_time' => 'total_watch_seconds',
    ];

    /**
     * What each platform can report (for the analytics page).
     *
     * @return array<string, array<int, string>>
     */
    public static function availability(): array
    {
        return [
            Platform::Facebook->label() => ['views', 'reach', 'likes', 'comments', 'shares', 'avg_watch_seconds', 'total_watch_seconds'],
            Platform::Instagram->label() => ['views', 'reach', 'likes', 'comments', 'shares', 'saves', 'avg_watch_seconds', 'total_watch_seconds'],
            Platform::YouTube->label() => ['views', 'likes', 'comments'],
        ];
    }

    public function __construct(
        private readonly MetaGraphClient $graph,
        private readonly GoogleClient $google,
        private readonly TokenManager $tokens,
    ) {}

    public function syncRecent(int $days = 30): int
    {
        $count = 0;
        ScheduledPost::with('socialAccount')
            ->where('status', PostStatus::Published->value)
            ->whereNotNull('external_post_id')
            ->where('published_at', '>=', now()->subDays($days))
            ->where(fn ($q) => $q->whereNull('metrics_synced_at')->orWhere('metrics_synced_at', '<', now()->subHours(5)))
            ->orderBy('metrics_synced_at')
            ->limit(200)
            ->get()
            ->each(function (ScheduledPost $post) use (&$count) {
                if ($post->socialAccount->isPublishable() && $this->syncPost($post)) {
                    $count++;
                }
            });

        return $count;
    }

    public function syncPost(ScheduledPost $post): ?PostMetric
    {
        try {
            $token = $this->tokens->accessToken($post->socialAccount);
            $values = match ($post->platform) {
                Platform::Instagram => $this->instagram($post, $token),
                Platform::Facebook => $this->facebook($post, $token),
                Platform::YouTube => $this->youtube($post, $token),
            };
        } catch (PublishException $e) {
            Log::info('Metrics unavailable', ['post' => $post->id, 'error' => $e->getMessage()]);
            $post->checkpoint(['metrics_synced_at' => now()]);

            return null;
        }

        $post->checkpoint(['metrics_synced_at' => now()]);

        return PostMetric::create(array_merge(['scheduled_post_id' => $post->id, 'captured_at' => now()], $values));
    }

    /**
     * @return array<string, mixed>
     */
    private function instagram(ScheduledPost $post, string $token): array
    {
        $data = $this->graph->get($post->external_post_id.'/insights', $token, [
            'metric' => implode(',', array_keys(self::IG_METRICS)),
        ], 'Instagram insights')['data'] ?? [];

        $out = ['raw' => ['source' => 'instagram_insights']];
        foreach ($data as $row) {
            $column = self::IG_METRICS[$row['name']] ?? null;
            $value = $row['values'][0]['value'] ?? $row['total_value']['value'] ?? null;
            if ($column && is_numeric($value)) {
                // Instagram reports watch time in milliseconds.
                $out[$column] = str_contains($column, 'watch') ? round($value / 1000, 2) : (int) $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function facebook(ScheduledPost $post, string $token): array
    {
        $videoId = $post->external_upload_id ?: $post->external_post_id;
        $out = ['raw' => ['source' => 'facebook_video_insights']];

        $insights = $this->graph->get($videoId.'/video_insights', $token, [
            'metric' => implode(',', array_keys(self::FB_METRICS)),
        ], 'Facebook Reels insights')['data'] ?? [];
        foreach ($insights as $row) {
            $column = self::FB_METRICS[$row['name']] ?? null;
            $value = $row['values'][0]['value'] ?? null;
            if ($column && is_numeric($value)) {
                $out[$column] = str_contains($column, 'watch') ? round($value / 1000, 2) : (int) $value;
            }
        }

        $social = $this->graph->get($videoId, $token, [
            'fields' => 'likes.summary(true).limit(0),comments.summary(true).limit(0)',
        ], 'Facebook Reels engagement');
        if (isset($social['likes']['summary']['total_count'])) {
            $out['likes'] = (int) $social['likes']['summary']['total_count'];
        }
        if (isset($social['comments']['summary']['total_count'])) {
            $out['comments'] = (int) $social['comments']['summary']['total_count'];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function youtube(ScheduledPost $post, string $token): array
    {
        $stats = $this->google->get('videos', $token, ['part' => 'statistics', 'id' => $post->external_post_id], 'YouTube statistics')['items'][0]['statistics'] ?? [];

        // Watch time needs the separate YouTube Analytics API; not collected.
        return array_filter([
            'views' => isset($stats['viewCount']) ? (int) $stats['viewCount'] : null,
            'likes' => isset($stats['likeCount']) ? (int) $stats['likeCount'] : null,
            'comments' => isset($stats['commentCount']) ? (int) $stats['commentCount'] : null,
            'raw' => ['source' => 'youtube_data_api_statistics'],
        ], fn ($v) => $v !== null);
    }
}
