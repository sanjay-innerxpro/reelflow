<x-layout title="Post #{{ $post->id }}">
    <x-slot:header>
        <div>
            <a href="{{ route('posts.index') }}" class="link text-sm">← History</a>
            <h1 class="flex items-center gap-2 text-xl font-semibold"><x-platform :platform="$post->platform" /> {{ $post->socialAccount->name }} · <x-badge :value="$post->status" /></h1>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($post->post_url)<a href="{{ $post->post_url }}" target="_blank" rel="noopener" class="btn">Open post ↗</a>@endif
            @if($post->status->canTransitionTo(\App\Enums\PostStatus::Cancelled))
                <form method="POST" action="{{ route('posts.cancel', $post) }}" data-confirm="Cancel this post?">@csrf<button class="btn">Cancel</button></form>
            @endif
            @if(in_array($post->status, [\App\Enums\PostStatus::Failed, \App\Enums\PostStatus::Cancelled]) && ! $post->requires_review)
                <form method="POST" action="{{ route('posts.retry', $post) }}">@csrf<button class="btn btn-primary">Retry now</button></form>
            @endif
        </div>
    </x-slot:header>

    @if($post->requires_review)
        <div class="card card-body mb-6 border-red-200 bg-red-50 text-sm">
            <h2 class="font-semibold text-red-800">Manual check required</h2>
            <p class="mt-1 text-red-700">ReelFlow could not confirm whether this post went live. To avoid a duplicate public post it will not retry automatically. Check the {{ $post->platform->label() }} account, then choose:</p>
            <div class="mt-3 flex flex-wrap items-end gap-3">
                <form method="POST" action="{{ route('posts.confirm', $post) }}" class="flex items-end gap-2">@csrf
                    <div><label class="label">Post URL (optional)</label><input name="post_url" type="url" class="input" placeholder="https://"></div>
                    <button class="btn">It is live — mark published</button>
                </form>
                <form method="POST" action="{{ route('posts.retry', $post) }}" data-confirm="Only continue if you verified the post is NOT live. Publish again?">@csrf
                    <input type="hidden" name="confirm_not_published" value="1">
                    <button class="btn btn-danger">It is not live — publish again</button>
                </form>
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card card-body text-sm">
            <h2 class="card-title mb-3">Details</h2>
            <dl class="grid grid-cols-2 gap-y-1.5">
                <dt class="text-slate-500">Video</dt><dd><a href="{{ route('videos.show', $post->video) }}" class="link">{{ \Illuminate\Support\Str::limit($post->video->original_filename, 30) }}</a></dd>
                <dt class="text-slate-500">Scheduled</dt><dd><x-time :at="$post->scheduled_at" format="d M Y, H:i" /></dd>
                <dt class="text-slate-500">Published</dt><dd><x-time :at="$post->published_at" format="d M Y, H:i" /></dd>
                <dt class="text-slate-500">Confirmed</dt><dd><x-time :at="$post->confirmed_at" format="d M Y, H:i" /></dd>
                <dt class="text-slate-500">Stage</dt><dd class="font-mono text-xs">{{ $post->stage->value }}</dd>
                <dt class="text-slate-500">Attempts</dt><dd>{{ $post->attempt_count }} / {{ $post->max_attempts }}</dd>
                <dt class="text-slate-500">Next attempt</dt><dd><x-time :at="$post->next_attempt_at" /></dd>
                <dt class="text-slate-500">Upload/video ID</dt><dd class="truncate font-mono text-xs">{{ $post->external_upload_id ?? '—' }}</dd>
                <dt class="text-slate-500">Container ID</dt><dd class="truncate font-mono text-xs">{{ $post->external_container_id ?? '—' }}</dd>
                <dt class="text-slate-500">Post ID</dt><dd class="truncate font-mono text-xs">{{ $post->external_post_id ?? '—' }}</dd>
                @if($post->reuse_sequence)<dt class="text-slate-500">Repost #</dt><dd>{{ $post->reuse_sequence }}</dd>@endif
            </dl>
            @if($post->last_error_message)
                <div class="mt-3 rounded bg-red-50 p-2 text-xs text-red-700"><strong>{{ $post->last_error_category?->label() }}</strong>: {{ $post->last_error_message }}</div>
            @endif

            @if($post->status === \App\Enums\PostStatus::Scheduled)
                <form method="POST" action="{{ route('posts.reschedule', $post) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-3">
                    @csrf @method('PATCH')
                    <label class="label">Reschedule ({{ \App\Support\DisplayTime::timezone() }})</label>
                    <input type="datetime-local" name="scheduled_at" required class="input" value="{{ $post->scheduled_at->copy()->setTimezone(\App\Support\DisplayTime::timezone())->format('Y-m-d\TH:i') }}">
                    <button class="btn btn-sm">Move</button>
                </form>
            @endif
        </div>

        <div class="space-y-6 lg:col-span-2">
            <div class="card">
                <div class="border-b border-slate-100 px-5 py-3"><h2 class="card-title">Publish attempts</h2></div>
                <table class="table">
                    <thead><tr><th>#</th><th>Started</th><th>Stage</th><th>Outcome</th><th>HTTP</th><th>Details</th></tr></thead>
                    <tbody>
                    @forelse($post->attempts as $attempt)
                        <tr>
                            <td>{{ $attempt->attempt_number }}</td>
                            <td class="whitespace-nowrap"><x-time :at="$attempt->started_at" format="d M H:i:s" /></td>
                            <td class="font-mono text-xs">{{ $attempt->stage }}</td>
                            <td><x-badge :value="$attempt->outcome === 'error' ? 'failed' : $attempt->outcome" />@if($attempt->retry_decision === 'retry')<div class="text-xs text-slate-500">retry <x-time :at="$attempt->retry_at" /></div>@endif</td>
                            <td>{{ $attempt->http_status ?? '—' }}</td>
                            <td class="max-w-sm text-xs">
                                @if($attempt->error_message)<div class="text-red-600">{{ $attempt->error_category?->label() }}: {{ $attempt->error_message }}</div>@endif
                                @if($attempt->response_meta)<code class="block break-all text-slate-500">{{ json_encode($attempt->response_meta, JSON_UNESCAPED_SLASHES) }}</code>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-slate-500">No attempts yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card card-body text-sm">
                <h2 class="card-title mb-2">Caption used</h2>
                @if($post->aiContent)
                    @if($post->aiContent->title)<p class="font-medium">{{ $post->aiContent->title }}</p>@endif
                    <p class="whitespace-pre-line text-slate-700">{{ $post->platform === \App\Enums\Platform::YouTube ? $post->aiContent->fullDescription() : $post->aiContent->fullCaption() }}</p>
                @else
                    <p class="text-slate-500">Resolved at publish time from the current approved caption.</p>
                @endif
            </div>

            <div class="card">
                <div class="border-b border-slate-100 px-5 py-3"><h2 class="card-title">Metrics snapshots</h2></div>
                <table class="table">
                    <thead><tr><th>Captured</th><th>Views</th><th>Likes</th><th>Comments</th><th>Shares</th><th>Avg watch</th></tr></thead>
                    <tbody>
                    @forelse($post->metrics as $m)
                        <tr>
                            <td><x-time :at="$m->captured_at" /></td>
                            @foreach(['views', 'likes', 'comments', 'shares'] as $f)<td>{{ $m->$f !== null ? number_format($m->$f) : 'n/a' }}</td>@endforeach
                            <td>{{ $m->avg_watch_seconds !== null ? $m->avg_watch_seconds.'s' : 'n/a' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-slate-500">No metrics collected (n/a means the platform does not provide it).</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layout>
