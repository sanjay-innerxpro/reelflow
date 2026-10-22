<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Support\DisplayTime;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function __invoke(Request $request, Settings $settings): View
    {
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['day', 'week'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'platform' => ['nullable', Rule::enum(Platform::class)],
        ]);

        $tz = DisplayTime::timezone();
        $mode = $filters['view'] ?? 'week';
        $anchor = CarbonImmutable::parse($filters['date'] ?? now($tz)->toDateString(), $tz)->startOfDay();
        $start = $mode === 'week' ? $anchor->startOfWeek() : $anchor;
        $end = $mode === 'week' ? $start->addDays(7) : $start->addDay();

        $posts = ScheduledPost::with(['video', 'socialAccount'])
            ->whereBetween('scheduled_at', [$start->utc(), $end->utc()])
            ->when($filters['platform'] ?? null, fn ($q, $p) => $q->where('platform', $p))
            ->where('status', '!=', PostStatus::Cancelled->value)
            ->orderBy('scheduled_at')
            ->get();

        $days = [];
        for ($d = $start; $d->lt($end); $d = $d->addDay()) {
            $days[$d->toDateString()] = $posts->filter(fn ($p) => $p->scheduled_at->copy()->setTimezone($tz)->toDateString() === $d->toDateString());
        }

        $accounts = SocialAccount::with('postingRule')->orderBy('platform')->get();
        $limits = [];
        foreach ($accounts as $account) {
            foreach ($days as $date => $dayPosts) {
                $limits[$account->id][$date] = [
                    'used' => $dayPosts->where('social_account_id', $account->id)->count(),
                    'limit' => $account->postingRule?->daily_limit,
                ];
            }
        }

        return view('calendar', [
            'mode' => $mode,
            'start' => $start,
            'anchor' => $anchor,
            'days' => $days,
            'filters' => $filters,
            'accounts' => $accounts,
            'limits' => $limits,
            'globalPaused' => $settings->bool('publishing.paused'),
        ]);
    }
}
