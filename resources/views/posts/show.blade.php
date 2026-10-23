<x-layout :title="'Post #'.$post->id">
    @php
        $stageOrder = array_map(fn ($s) => $s->value, \App\Enums\PublishStage::cases());
        $reached = array_search($post->stage->value, $stageOrder, true);
        $failed = in_array($post->status, [\App\Enums\PostStatus::Failed, \App\Enums\PostStatus::Cancelled], true);
        $steps = [
            ['Scheduled', 'pending', $post->scheduled_at],
            ['Uploaded', 'uploaded', null],
            ['Processed', 'processed', null],
            ['Published', 'published', $post->published_at],
            ['Confirmed', 'confirmed', $post->confirmed_at],
        ];
        $content = $post->aiContent;
        $caption = $content ? ($post->platform === \App\Enums\Platform::YouTube ? $content->fullDescription() : $content->fullCaption()) : null;
    @endphp

    <x-slot:header>
        <div class="min-w-0">
            <a href="{{ route('posts.index') }}" class="link inline-flex items-center gap-1 text-sm"><x-icon name="arrow-left" class="h-3.5 w-3.5" /> History</a>
            <h1 class="mt-1 truncate">{{ $post->video->original_filename }}</h1>
            <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <x-platform :platform="$post->platform" class="h-5 w-5 rounded-full" />
                <span class="font-medium text-slate-700">{{ $post->socialAccount->name }}</span>
                <span>·</span>
                <span>{{ $post->platform->label() }}</span>
                <x-badge :value="$post->status" />
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @if($post->status->canTransitionTo(\App\Enums\PostStatus::Cancelled))
                <form method="POST" action="{{ route('posts.cancel', $post) }}" data-confirm="Cancel this post?">@csrf<button class="btn">Cancel post</button></form>
            @endif
            @if(in_array($post->status, [\App\Enums\PostStatus::Failed, \App\Enums\PostStatus::Cancelled]) && ! $post->requires_review)
                <form method="POST" action="{{ route('posts.retry', $post) }}">@csrf<button class="btn btn-primary"><x-icon name="refresh" class="h-4 w-4" /> Retry now</button></form>
            @endif
            @if($post->post_url)<a href="{{ $post->post_url }}" target="_blank" rel="noopener" class="btn btn-primary">View on {{ $post->platform === \App\Enums\Platform::YouTube ? 'YouTube' : ($post->platform === \App\Enums\Platform::Instagram ? 'Instagram' : 'Facebook') }} <x-icon name="arrow-right" class="h-4 w-4 -rotate-45" /></a>@endif
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

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <div class="space-y-6">
            {{-- Summary --}}
            <div class="card overflow-hidden">
                <div class="flex flex-col gap-5 p-5 sm:flex-row">
                    <a href="{{ route('videos.show', $post->video) }}" class="block aspect-[9/16] w-28 shrink-0 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
                        @if($post->video->thumbnail_key)<img src="{{ route('videos.thumbnail', $post->video) }}" alt="" class="h-full w-full object-cover">@endif
                    </a>
                    <div class="min-w-0 flex-1">
                        <div class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Caption</div>
                        @if($caption)
                            @if($content->title)<p class="mt-1.5 font-medium text-slate-900">{{ $content->title }}</p>@endif
                            <p class="mt-1.5 text-sm leading-relaxed whitespace-pre-line text-slate-700">{{ $caption }}</p>
                        @else
                            <p class="mt-1.5 text-sm text-slate-500">Taken from the current approved caption at publish time.</p>
                        @endif
                    </div>
                </div>
                <dl class="grid grid-cols-2 divide-slate-100 border-t border-slate-100 bg-slate-50/60 sm:grid-cols-4 sm:divide-x">
                    @foreach([['Scheduled', $post->scheduled_at], ['Published', $post->published_at], ['Confirmed', $post->confirmed_at]] as [$label, $at])
                        <div class="px-5 py-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-0.5 text-sm font-medium text-slate-900"><x-time :at="$at" format="d M Y, H:i" /></dd></div>
                    @endforeach
                    <div class="px-5 py-3"><dt class="text-xs text-slate-500">Attempts</dt><dd class="mt-0.5 text-sm font-medium text-slate-900">{{ $post->attempt_count }} <span class="font-normal text-slate-400">of {{ $post->max_attempts }}</span></dd></div>
                </dl>
            </div>

            {{-- Progress --}}
            <div class="card card-body">
                <h2 class="card-title">Publishing progress</h2>
                <ol class="mt-5 grid grid-cols-5">
                    @foreach($steps as $i => [$label, $stage, $at])
                        @php
                            $done = $reached !== false && $reached >= array_search($stage, $stageOrder, true);
                            $current = ! $done && ($i === 0 || ($reached !== false && $reached >= array_search($steps[$i - 1][1], $stageOrder, true)));
                        @endphp
                        <li class="relative flex flex-col items-center text-center">
                            @if($i > 0)<span class="absolute top-4 right-1/2 h-0.5 w-full {{ $done ? 'bg-emerald-500' : 'bg-slate-200' }}"></span>@endif
                            <span @class([
                                'relative z-10 flex h-8 w-8 items-center justify-center rounded-full text-xs font-semibold ring-4 ring-white',
                                'bg-emerald-500 text-on-color' => $done,
                                'bg-red-500 text-on-color' => ! $done && $current && $failed,
                                'brand-gradient text-on-color' => ! $done && $current && ! $failed,
                                'bg-slate-100 text-slate-400' => ! $done && ! $current,
                            ])>
                                @if($done)<x-icon name="check" class="h-4 w-4" />@elseif($current && $failed)<x-icon name="x" class="h-4 w-4" />@else{{ $i + 1 }}@endif
                            </span>
                            <span class="mt-2 text-xs font-medium {{ $done || $current ? 'text-slate-900' : 'text-slate-400' }}">{{ $label }}</span>
                            @if($at)<span class="text-[11px] text-slate-500"><x-time :at="$at" format="H:i" /></span>@endif
                        </li>
                    @endforeach
                </ol>
                @if($post->last_error_message)
                    <div class="mt-5 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700">
                        <x-icon name="alert" class="h-5 w-5 shrink-0" />
                        <div><span class="font-medium">{{ $post->last_error_category?->label() }}:</span> {{ $post->last_error_message }}
                            @if($post->next_attempt_at)<div class="mt-0.5 text-xs">Next attempt <x-time :at="$post->next_attempt_at" /></div>@endif
                        </div>
                    </div>
                @endif
            </div>

            {{-- Attempts --}}
            <div class="card">
                <div class="card-header"><h2 class="card-title">Publish attempts</h2><span class="text-xs text-slate-500">{{ $post->attempts->count() }} total</span></div>
                <ul class="divide-y divide-slate-100">
                    @forelse($post->attempts as $attempt)
                        <li class="flex flex-wrap items-start gap-x-4 gap-y-1 px-5 py-3 text-sm">
                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-600">{{ $attempt->attempt_number }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-badge :value="$attempt->outcome === 'error' ? 'failed' : $attempt->outcome" />
                                    <span class="text-slate-500">started <x-time :at="$attempt->started_at" format="d M, H:i:s" /> from stage <span class="font-medium text-slate-700">{{ str_replace('_', ' ', $attempt->stage) }}</span></span>
                                    @if($attempt->http_status)<span class="text-xs text-slate-400">HTTP {{ $attempt->http_status }}</span>@endif
                                </div>
                                @if($attempt->error_message)<p class="mt-1 text-red-600">{{ $attempt->error_category?->label() }}: {{ $attempt->error_message }}</p>@endif
                                @if($attempt->response_meta['note'] ?? null)<p class="mt-1 text-slate-500">{{ $attempt->response_meta['note'] }}</p>@endif
                                @if($attempt->retry_decision === 'retry')<p class="mt-1 text-xs text-slate-500">Retry scheduled <x-time :at="$attempt->retry_at" /></p>@endif
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-sm text-slate-500">No attempts yet. Publishing starts at the scheduled time.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="space-y-6">
            @if($post->status === \App\Enums\PostStatus::Scheduled)
                <form method="POST" action="{{ route('posts.reschedule', $post) }}" class="card card-body space-y-3">
                    @csrf @method('PATCH')
                    <h2 class="card-title">Reschedule</h2>
                    <input type="datetime-local" name="scheduled_at" required class="input" value="{{ $post->scheduled_at->copy()->setTimezone(\App\Support\DisplayTime::timezone())->format('Y-m-d\TH:i') }}">
                    <p class="help -mt-1">Time zone: {{ \App\Support\DisplayTime::timezone() }}</p>
                    <button class="btn w-full">Move post</button>
                </form>
            @endif

            <div class="card">
                <div class="card-header"><h2 class="card-title">Performance</h2></div>
                @php($latest = $post->metrics->first())
                @if($latest)
                    <dl class="grid grid-cols-2 gap-px bg-slate-100">
                        @foreach(['views' => 'Views', 'likes' => 'Likes', 'comments' => 'Comments', 'shares' => 'Shares'] as $field => $label)
                            <div class="bg-white px-5 py-3"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-0.5 text-lg font-semibold text-slate-900">{{ $latest->$field !== null ? number_format($latest->$field) : '—' }}</dd></div>
                        @endforeach
                    </dl>
                    <p class="px-5 py-2.5 text-xs text-slate-500">Updated <x-time :at="$latest->captured_at" /></p>
                @else
                    <p class="px-5 py-5 text-sm text-slate-500">Views and likes appear here a few hours after publishing.</p>
                @endif
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">Technical details</h2></div>
                <dl class="divide-y divide-slate-100 text-sm">
                    @foreach([
                        'Post ID' => $post->external_post_id,
                        'Video ID' => $post->external_upload_id,
                        'Container ID' => $post->external_container_id,
                        'Stage' => str_replace('_', ' ', $post->stage->value),
                        'Repost #' => $post->reuse_sequence ?: null,
                    ] as $label => $value)
                        @continue($value === null && $label !== 'Post ID')
                        <div class="flex items-center justify-between gap-3 px-5 py-2.5">
                            <dt class="text-slate-500">{{ $label }}</dt>
                            <dd class="truncate font-mono text-xs text-slate-700" title="{{ $value }}">{{ $value ?? '—' }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>
    </div>
</x-layout>
