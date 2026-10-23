<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Models\AiGeneration;
use App\Models\ScheduledPost;
use App\Models\User;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\View\View;

/**
 * Super-admin overview: how the whole install is doing, across workspaces.
 * Its job is to make a bad day visible at a glance — AI captions failing,
 * posts failing, a workspace out of space — and to say what failed and why.
 */
class OverviewController extends Controller
{
    private const RANGES = [7, 14, 30];

    public function __invoke(Request $request): View
    {
        $days = (int) ($request->validate(['days' => ['nullable', 'integer', 'in:'.implode(',', self::RANGES)]])['days'] ?? 14);
        $since = now()->subDays($days - 1)->startOfDay();

        $generations = AiGeneration::allWorkspaces()->where('created_at', '>=', $since);
        $byStatus = (clone $generations)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $finished = $byStatus->except(AiGeneration::STATUS_RUNNING)->sum();
        $good = $byStatus[AiGeneration::STATUS_SUCCEEDED] ?? 0;
        $low = $byStatus[AiGeneration::STATUS_LOW_CONFIDENCE] ?? 0;

        return view('admin.overview', [
            'days' => $days,
            'ranges' => self::RANGES,
            'users' => User::count(),
            'workspaces' => Workspace::count(),
            'suspended' => Workspace::whereNotNull('suspended_at')->count(),
            'videos' => Video::allWorkspaces()->count(),
            'storageUsed' => (int) Video::allWorkspaces()->whereNotNull('storage_key')->whereNull('storage_deleted_at')->sum('size_bytes'),
            'published' => ScheduledPost::allWorkspaces()->where('status', PostStatus::Published->value)->where('published_at', '>=', $since)->count(),
            'aiSuccessRate' => $finished > 0 ? round(100 * ($good + $low) / $finished) : null,
            'aiFinished' => $finished,
            'aiRunning' => $byStatus[AiGeneration::STATUS_RUNNING] ?? 0,
            'aiPerDay' => $this->aiPerDay($since, $days),
            'aiFailures' => $this->aiFailureReasons($since),
            'recentFailures' => $this->recentFailures(),
            'publishing' => $this->publishing($since),
            'storagePerWorkspace' => $this->storagePerWorkspace(),
        ]);
    }

    /**
     * @return array<int, array{label: string, values: array<string, int>}>
     */
    private function aiPerDay(Carbon $since, int $days): array
    {
        $counts = AiGeneration::allWorkspaces()
            ->where('created_at', '>=', $since)
            ->selectRaw('date(created_at) as day, status, count(*) as n')
            ->groupBy('day', 'status')
            ->get()
            ->groupBy('day');

        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $since->copy()->addDays($i);
            $forDay = $counts[$day->toDateString()] ?? collect();
            $rows[] = [
                // Every other label on the longer ranges, so they never collide.
                'label' => $days > 14 && $i % 2 ? '' : $day->format('j M'),
                'values' => [
                    AiGeneration::STATUS_SUCCEEDED => (int) $forDay->firstWhere('status', AiGeneration::STATUS_SUCCEEDED)?->n,
                    AiGeneration::STATUS_LOW_CONFIDENCE => (int) $forDay->firstWhere('status', AiGeneration::STATUS_LOW_CONFIDENCE)?->n,
                    AiGeneration::STATUS_FAILED => (int) $forDay->firstWhere('status', AiGeneration::STATUS_FAILED)?->n,
                ],
            ];
        }

        return $rows;
    }

    /**
     * Failed generations grouped by what actually went wrong, so the fix is
     * obvious: a quota problem is not a prompt problem.
     *
     * @return array<int, array{label: string, value: int, hint: string, mark: string}>
     */
    private function aiFailureReasons(Carbon $since): array
    {
        $failures = AiGeneration::allWorkspaces()
            ->where('created_at', '>=', $since)
            ->where('status', AiGeneration::STATUS_FAILED)
            ->pluck('error');

        $buckets = [];
        foreach ($failures as $error) {
            [$label, $hint] = self::classify((string) $error);
            $buckets[$label] ??= ['label' => $label, 'value' => 0, 'hint' => $hint, 'mark' => 'bad'];
            $buckets[$label]['value']++;
        }

        usort($buckets, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $buckets;
    }

    /**
     * @return array{0: string, 1: string} [bucket, what to do about it]
     */
    public static function classify(string $error): array
    {
        $text = strtolower($error);

        return match (true) {
            str_contains($text, 'rate limit') || str_contains($text, '429') || str_contains($text, 'quota') => ['Provider rate limit or quota', 'The free tier is exhausted for now; generation retries on its own.'],
            str_contains($text, 'daily limit') || str_contains($text, 'budget') => ['Workspace or budget limit', 'Raise the daily AI limit for the workspace, or wait for the next day.'],
            str_contains($text, 'could not reach') || str_contains($text, 'timed out') || str_contains($text, 'timeout') => ['Endpoint unreachable', 'Check AI_BASE_URL and the network, or raise AI_TIMEOUT.'],
            str_contains($text, 'cut off') => ['Answer cut off', 'Raise AI_MAX_OUTPUT_TOKENS.'],
            str_contains($text, 'schema validation') || str_contains($text, 'not valid json') || str_contains($text, 'json object') || str_contains($text, 'missing') => ['Unusable answer shape', 'The model ignored the JSON format. Try another model, or AI_JSON_MODE=json_schema.'],
            str_contains($text, 'no frames') => ['No frames extracted', 'FFmpeg could not read the video, or the sync agent sent no frames.'],
            str_contains($text, 'declined') => ['Model declined the video', 'The model refused to describe this content; write the captions by hand.'],
            str_contains($text, 'api_key') || str_contains($text, 'unauthor') || str_contains($text, '401') || str_contains($text, '403') => ['Key rejected', 'Check the AI API keys in Admin → Credentials and that the model is enabled for them.'],
            default => ['Other', 'Open the generation to see the provider error in full.'],
        };
    }

    /**
     * @return Collection<int, AiGeneration>
     */
    private function recentFailures(): Collection
    {
        return AiGeneration::allWorkspaces()
            ->with(['video' => fn ($q) => $q->withoutGlobalScopes(), 'workspace'])
            ->where('status', AiGeneration::STATUS_FAILED)
            ->latest('completed_at')
            ->limit(15)
            ->get();
    }

    /**
     * @return array<int, array{label: string, values: array<string, int>}>
     */
    private function publishing(Carbon $since): array
    {
        $rows = [];
        foreach (Platform::cases() as $platform) {
            $counts = ScheduledPost::allWorkspaces()
                ->where('platform', $platform->value)
                ->where('updated_at', '>=', $since)
                ->selectRaw('status, count(*) as n')
                ->groupBy('status')
                ->pluck('n', 'status');

            $rows[] = ['label' => $platform->label(), 'values' => [
                'published' => (int) ($counts[PostStatus::Published->value] ?? 0),
                'pending' => (int) ($counts[PostStatus::Scheduled->value] ?? 0),
                'failed' => (int) ($counts[PostStatus::Failed->value] ?? 0),
            ]];
        }

        return $rows;
    }

    /**
     * @return array<int, array{label: string, value: int, hint: string, mark: string}>
     */
    private function storagePerWorkspace(): array
    {
        $used = Video::allWorkspaces()
            ->whereNotNull('storage_key')->whereNull('storage_deleted_at')
            ->selectRaw('workspace_id, sum(size_bytes) as n')
            ->groupBy('workspace_id')
            ->pluck('n', 'workspace_id');

        return Workspace::orderBy('name')->get()
            ->map(function (Workspace $workspace) use ($used) {
                $bytes = (int) ($used[$workspace->id] ?? 0);
                $limit = $workspace->storageLimitBytes();
                $pct = $limit ? round(100 * $bytes / $limit) : null;

                return [
                    'label' => $workspace->name,
                    'value' => $bytes,
                    // Against its own limit: what matters is who is about to run out.
                    'ratio' => $limit ? $bytes / $limit : null,
                    'hint' => $limit
                        ? $pct.'% of '.Number::fileSize($limit).($workspace->isSuspended() ? ' · suspended' : '')
                        : 'no limit',
                    'mark' => $pct !== null && $pct >= 90 ? 'bad' : ($pct !== null && $pct >= 75 ? 'warn' : 'magnitude'),
                ];
            })
            ->sortByDesc('value')
            ->take(10)
            ->values()
            ->all();
    }
}
