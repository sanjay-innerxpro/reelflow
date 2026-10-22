<x-layout :title="$title">
    <x-slot:header>
        <h1 class="text-xl font-semibold">{{ $title }}</h1>
    </x-slot:header>

    <form method="GET" class="card card-body mb-4 flex flex-wrap items-end gap-3">
        @unless($failedView ?? false)
            <div>
                <label class="label">Status</label>
                <select name="status" class="input">
                    <option value="">Any</option>
                    @foreach(\App\Enums\PostStatus::cases() as $s)
                        <option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ ucfirst($s->value) }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <div>
            <label class="label">Platform</label>
            <select name="platform" class="input">
                <option value="">All</option>
                @foreach(\App\Enums\Platform::cases() as $p)
                    <option value="{{ $p->value }}" @selected(($filters['platform'] ?? '') === $p->value)>{{ $p->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">Account</label>
            <select name="account" class="input">
                <option value="">All</option>
                @foreach($accounts as $a)
                    <option value="{{ $a->id }}" @selected((int) ($filters['account'] ?? 0) === $a->id)>{{ $a->platform->value }} · {{ $a->name }}</option>
                @endforeach
            </select>
        </div>
        @unless($failedView ?? false)
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="review" value="1" @checked($filters['review'] ?? false)> Needs manual review</label>
        @endunless
        <button class="btn btn-primary">Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Scheduled</th><th>Platform</th><th>Video</th><th>Status</th><th>Attempts</th><th>Result</th><th></th></tr></thead>
            <tbody>
            @forelse($posts as $post)
                <tr>
                    <td class="whitespace-nowrap"><x-time :at="$post->scheduled_at" /></td>
                    <td class="whitespace-nowrap"><x-platform :platform="$post->platform" /> {{ $post->socialAccount->name }}</td>
                    <td><a href="{{ route('videos.show', $post->video) }}" class="link">{{ \Illuminate\Support\Str::limit($post->video->original_filename, 40) }}</a></td>
                    <td>
                        <x-badge :value="$post->status" />
                        @if($post->requires_review)<x-badge color="red">check manually</x-badge>@endif
                    </td>
                    <td>{{ $post->attempt_count }}/{{ $post->max_attempts }}</td>
                    <td class="max-w-sm text-xs">
                        @if($post->post_url)
                            <a href="{{ $post->post_url }}" target="_blank" rel="noopener" class="link">View post ↗</a>
                        @elseif($post->last_error_message)
                            <span class="text-red-600">{{ $post->last_error_category?->label() }}:</span> {{ \Illuminate\Support\Str::limit($post->last_error_message, 140) }}
                        @endif
                    </td>
                    <td class="whitespace-nowrap text-right">
                        <a href="{{ route('posts.show', $post) }}" class="btn btn-sm">Details</a>
                        @if($post->status === \App\Enums\PostStatus::Failed && ! $post->requires_review)
                            <form method="POST" action="{{ route('posts.retry', $post) }}" class="inline">@csrf<button class="btn btn-sm">Retry</button></form>
                        @endif
                        @if($post->status->canTransitionTo(\App\Enums\PostStatus::Cancelled))
                            <form method="POST" action="{{ route('posts.cancel', $post) }}" class="inline" data-confirm="Cancel this post?">@csrf<button class="btn btn-sm">Cancel</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-500">No posts match.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $posts->links() }}</div>
</x-layout>
