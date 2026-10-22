<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\AiGeneration;
use App\Models\PostMetric;
use App\Models\ScheduledPost;
use App\Services\Analytics\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'in:7,30,90']])['days'] ?? 30);
        $since = now()->subDays($days);

        $outcomes = [];
        foreach (Platform::cases() as $platform) {
            $base = ScheduledPost::where('platform', $platform->value)->where('updated_at', '>=', $since);
            $published = (clone $base)->where('status', PostStatus::Published->value)->count();
            $failed = (clone $base)->where('status', PostStatus::Failed->value)->count();
            $outcomes[$platform->label()] = [
                'published' => $published,
                'failed' => $failed,
                'success_rate' => ($published + $failed) > 0 ? round(100 * $published / ($published + $failed), 1) : null,
            ];
        }

        // Latest snapshot per post; NULL stays NULL (not available).
        $latestIds = PostMetric::select(DB::raw('MAX(id)'))->groupBy('scheduled_post_id');
        $metrics = PostMetric::with('scheduledPost.video', 'scheduledPost.socialAccount')
            ->whereIn('id', $latestIds)
            ->whereHas('scheduledPost', fn ($q) => $q->where('published_at', '>=', $since))
            ->orderByDesc('views')
            ->limit(50)
            ->get();

        $ai = AiGeneration::where('created_at', '>=', $since);

        return view('analytics', [
            'days' => $days,
            'outcomes' => $outcomes,
            'metrics' => $metrics,
            'availability' => MetricsService::availability(),
            'aiCost' => (clone $ai)->sum('cost_usd'),
            'aiCostKnown' => (clone $ai)->whereNotNull('cost_usd')->count(),
            'aiRuns' => (clone $ai)->count(),
            'aiTokens' => [(clone $ai)->sum('input_tokens'), (clone $ai)->sum('output_tokens')],
        ]);
    }
}
