<?php

namespace App\Http\Controllers;

use App\Enums\Platform;
use App\Enums\PostStatus;
use App\Http\Requests\ScheduleVideoRequest;
use App\Models\ScheduledPost;
use App\Models\SocialAccount;
use App\Models\Video;
use App\Services\Scheduling\ScheduleService;
use App\Support\DisplayTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PostController extends Controller
{
    public function __construct(private readonly ScheduleService $schedule) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(PostStatus::class)],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'account' => ['nullable', 'integer'],
            'review' => ['nullable', 'boolean'],
        ]);

        return view('posts.index', [
            'title' => 'Publishing history',
            'posts' => $this->query($filters)->latest('scheduled_at')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'accounts' => SocialAccount::orderBy('platform')->get(),
        ]);
    }

    public function failed(Request $request): View
    {
        $filters = ['status' => PostStatus::Failed->value] + $request->validate([
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'account' => ['nullable', 'integer'],
        ]);

        return view('posts.index', [
            'title' => 'Failed posts',
            'posts' => $this->query($filters)->latest('updated_at')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'accounts' => SocialAccount::orderBy('platform')->get(),
            'failedView' => true,
        ]);
    }

    public function show(ScheduledPost $post): View
    {
        Gate::authorize('view', $post);
        $post->load(['video', 'socialAccount', 'aiContent', 'attempts' => fn ($q) => $q->orderByDesc('attempt_number'), 'metrics' => fn ($q) => $q->latest('captured_at')->limit(10)]);

        return view('posts.show', ['post' => $post]);
    }

    public function store(ScheduleVideoRequest $request, Video $video): RedirectResponse
    {
        Gate::authorize('update', $video);
        $data = $request->validated();
        $at = filled($data['scheduled_at'] ?? null)
            ? CarbonImmutable::parse($data['scheduled_at'], DisplayTime::timezone())
            : CarbonImmutable::now();

        $created = 0;
        $errors = [];
        foreach (SocialAccount::whereIn('id', $data['account_ids'])->get() as $account) {
            try {
                $this->schedule->schedule($video, $account, $at, $request->boolean('allow_reuse'));
                $created++;
            } catch (ValidationException $e) {
                $errors[] = $account->platform->label().' ('.$account->name.'): '.implode(' ', $e->validator->errors()->all());
            }
        }

        $redirect = back();
        if ($created) {
            $redirect->with('status', "Scheduled {$created} post(s) for ".DisplayTime::format($at->utc()).'.');
        }

        return $errors ? $redirect->withErrors(['schedule' => $errors]) : $redirect;
    }

    public function reschedule(Request $request, ScheduledPost $post): RedirectResponse
    {
        Gate::authorize('update', $post);
        $data = $request->validate(['scheduled_at' => ['required', 'date']]);
        $this->schedule->reschedule($post, CarbonImmutable::parse($data['scheduled_at'], DisplayTime::timezone()));

        return back()->with('status', 'Post rescheduled.');
    }

    public function cancel(ScheduledPost $post): RedirectResponse
    {
        Gate::authorize('update', $post);
        $this->schedule->cancel($post);

        return back()->with('status', 'Post cancelled.');
    }

    public function retry(Request $request, ScheduledPost $post): RedirectResponse
    {
        Gate::authorize('update', $post);
        $this->schedule->retry($post, $request->boolean('confirm_not_published'));

        return back()->with('status', 'Post queued for another attempt.');
    }

    public function confirmPublished(Request $request, ScheduledPost $post): RedirectResponse
    {
        Gate::authorize('update', $post);
        $data = $request->validate(['post_url' => ['nullable', 'url', 'max:500']]);
        $this->schedule->markPublishedManually($post, $data['post_url'] ?? null);

        return back()->with('status', 'Marked as published.');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function query(array $filters)
    {
        return ScheduledPost::with(['video', 'socialAccount'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['platform'] ?? null, fn ($q, $p) => $q->where('platform', $p))
            ->when($filters['account'] ?? null, fn ($q, $a) => $q->where('social_account_id', $a))
            ->when($filters['review'] ?? false, fn ($q) => $q->where('requires_review', true));
    }
}
