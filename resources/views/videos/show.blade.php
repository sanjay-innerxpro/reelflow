<x-layout :title="$video->original_filename">
    <x-slot:header>
        <div class="min-w-0">
            <a href="{{ route('videos.index') }}" class="link text-sm">← Library</a>
            <h1 class="truncate text-xl font-semibold">{{ $video->original_filename }}</h1>
            <div class="text-sm text-slate-500">{{ $video->folder ?: '(root folder)' }}</div>
        </div>
        <div class="flex flex-wrap gap-2">
            <form method="POST" action="{{ route('videos.generate', $video) }}">@csrf
                <button class="btn btn-primary" @disabled(! $aiConfigured)>{{ $video->aiContents->isEmpty() && $video->ai_status->value !== 'completed' ? 'Generate captions' : 'Regenerate captions' }}</button>
            </form>
            <form method="POST" action="{{ route('videos.reprocess', $video) }}">@csrf
                <button class="btn">Re-run processing</button>
            </form>
        </div>
    </x-slot:header>

    @unless($aiConfigured)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">AI captioning is not configured (AI_PROVIDER / AI_API_KEY). You can still write captions manually below.</div>
    @endunless

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <div class="card overflow-hidden">
                @if($video->hasStoredFile())
                    <video controls preload="metadata" class="aspect-[9/16] w-full bg-black" @if($thumbnail) poster="{{ $thumbnail }}" @endif>
                        <source src="{{ route('videos.media', $video) }}" type="{{ $video->mime_type ?? 'video/mp4' }}">
                    </video>
                @else
                    <div class="flex aspect-[9/16] items-center justify-center bg-slate-100 p-6 text-center text-sm text-slate-500">
                        {{ $video->storage_deleted_at ? 'Original removed from storage after publishing.' : 'Video not uploaded yet.' }}
                    </div>
                @endif
            </div>

            <div class="card card-body text-sm">
                <h2 class="card-title mb-3">Details</h2>
                <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5">
                    <dt class="text-slate-500">Status</dt><dd><x-badge :value="$video->status" /></dd>
                    <dt class="text-slate-500">AI</dt><dd><x-badge :value="$video->ai_status" />@if($video->ai_confidence !== null) <span class="text-xs text-slate-500">{{ number_format($video->ai_confidence * 100) }}%</span>@endif</dd>
                    <dt class="text-slate-500">Resolution</dt><dd>{{ $video->width && $video->height ? $video->width.'×'.$video->height : '—' }} {{ $video->isVertical() ? '(vertical)' : '' }}</dd>
                    <dt class="text-slate-500">Duration</dt><dd>{{ $video->duration_seconds ? number_format($video->duration_seconds, 1).' s' : '—' }}</dd>
                    <dt class="text-slate-500">Size</dt><dd>{{ \App\Support\Bytes::human($video->size_bytes) }}</dd>
                    <dt class="text-slate-500">Codecs</dt><dd>{{ $video->video_codec ?? '—' }} / {{ $video->has_audio ? $video->audio_codec : 'no audio' }}</dd>
                    <dt class="text-slate-500">Frame rate</dt><dd>{{ $video->frame_rate ? $video->frame_rate.' fps' : '—' }}</dd>
                    <dt class="text-slate-500">Language</dt><dd>{{ $video->language ?? '—' }}</dd>
                    <dt class="text-slate-500">Topic</dt><dd>{{ $video->topic ?? '—' }}</dd>
                    <dt class="text-slate-500">Uploaded</dt><dd><x-time :at="$video->uploaded_at" /></dd>
                    <dt class="text-slate-500">SHA-256</dt><dd class="truncate font-mono text-xs" title="{{ $video->checksum_sha256 }}">{{ substr($video->checksum_sha256, 0, 16) }}…</dd>
                </dl>
                @if($video->processing_error)
                    <div class="mt-3 rounded bg-red-50 p-2 text-xs text-red-700">{{ $video->processing_error }}</div>
                @endif
                <form method="POST" action="{{ route('videos.update', $video) }}" class="mt-4 space-y-2 border-t border-slate-100 pt-3">
                    @csrf @method('PATCH')
                    <label class="label">Category</label>
                    <input name="category" value="{{ $video->category }}" class="input">
                    <label class="flex items-center gap-2"><input type="checkbox" name="allow_reuse" value="1" @checked($video->allow_reuse)> Allow this video to be posted again later</label>
                    <button class="btn btn-sm">Save</button>
                </form>
            </div>

            <div class="card card-body text-sm">
                <h2 class="card-title mb-2">Local copies ({{ $video->sources->count() }})</h2>
                <ul class="space-y-1 text-xs">
                    @forelse($video->sources as $source)
                        <li><span class="font-medium">{{ $source->syncDevice->name }}</span> · {{ $source->folder_label }}/{{ $source->relative_path }}</li>
                    @empty
                        <li class="text-slate-500">None recorded.</li>
                    @endforelse
                </ul>
                @if($video->sources->count() > 1)
                    <p class="help">The same file exists in several folders; it was uploaded and stored once.</p>
                @endif
            </div>
        </div>

        <div class="space-y-6 lg:col-span-2">
            @foreach(\App\Enums\Platform::cases() as $platform)
                @php($item = $content[$platform->value]['model'])
                <div class="card">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-3">
                        <h2 class="card-title flex items-center gap-2"><x-platform :platform="$platform" /> {{ $platform->label() }}</h2>
                        <div class="flex items-center gap-2 text-xs">
                            @if($item)
                                <x-badge :value="$item->validation_status" />
                                @if($item->confidence !== null)<span class="text-slate-500">confidence {{ number_format($item->confidence * 100) }}%</span>@endif
                                <span class="text-slate-400">{{ $item->provider }}{{ $item->model ? ' / '.$item->model : '' }}{{ $item->prompt_version ? ' · prompt '.$item->prompt_version : '' }}</span>
                            @else
                                <x-badge color="gray">no caption</x-badge>
                            @endif
                        </div>
                    </div>
                    <form method="POST" action="{{ route('content.update', [$video, $platform->value]) }}" class="space-y-3 p-5">
                        @csrf @method('PUT')
                        @if($platform === \App\Enums\Platform::YouTube)
                            <div>
                                <label class="label">Title (max 100)</label>
                                <input name="title" maxlength="100" value="{{ old('title', $item?->title) }}" class="input">
                            </div>
                            <div>
                                <label class="label">Description</label>
                                <textarea name="description" rows="4" class="input">{{ old('description', $item?->description) }}</textarea>
                            </div>
                            <div>
                                <label class="label">Tags (comma separated)</label>
                                <input name="tags" value="{{ old('tags', implode(', ', $item?->tags ?? [])) }}" class="input">
                            </div>
                        @else
                            <div>
                                <label class="label">Caption</label>
                                <textarea name="caption" rows="4" class="input">{{ old('caption', $item?->caption) }}</textarea>
                            </div>
                        @endif
                        <div>
                            <label class="label">Hashtags (space separated{{ $platform === \App\Enums\Platform::Instagram ? ', max '.config('reelflow.meta.instagram_max_hashtags') : '' }})</label>
                            <input name="hashtags" value="{{ old('hashtags', collect($item?->hashtags ?? [])->map(fn ($t) => '#'.$t)->implode(' ')) }}" class="input">
                        </div>

                        @if($item?->content_warnings)
                            <ul class="rounded bg-amber-50 p-2 text-xs text-amber-800">
                                @foreach($item->content_warnings as $warning)<li>⚠ {{ $warning }}</li>@endforeach
                            </ul>
                        @endif
                        @if($content[$platform->value]['format'])
                            <ul class="rounded bg-red-50 p-2 text-xs text-red-700">
                                @foreach($content[$platform->value]['format'] as $problem)<li>✗ {{ $problem }}</li>@endforeach
                            </ul>
                        @endif
                        @if($content[$platform->value]['problem'])
                            <p class="text-xs text-amber-700">Not publishable yet: {{ $content[$platform->value]['problem'] }}</p>
                        @endif

                        <div class="flex flex-wrap items-center gap-2">
                            <button class="btn">Save edits</button>
                            <button name="approve" value="1" class="btn btn-primary">Save &amp; approve</button>
                        </div>
                    </form>
                    @if($item)
                        <div class="flex gap-2 px-5 pb-4">
                            <form method="POST" action="{{ route('content.approve', [$video, $platform->value]) }}">@csrf<button class="btn btn-sm">Approve as is</button></form>
                            <form method="POST" action="{{ route('content.reject', [$video, $platform->value]) }}">@csrf<button class="btn btn-sm">Reject</button></form>
                        </div>
                    @endif
                </div>
            @endforeach

            <div class="card card-body">
                <h2 class="card-title mb-3">Schedule manually</h2>
                <form method="POST" action="{{ route('videos.schedule', $video) }}" class="space-y-3 text-sm">
                    @csrf
                    <div class="grid gap-2 sm:grid-cols-2">
                        @forelse($accounts as $account)
                            <label class="flex items-center gap-2"><input type="checkbox" name="account_ids[]" value="{{ $account->id }}" @disabled(! $account->isPublishable())>
                                <x-platform :platform="$account->platform" /> {{ $account->name }} @unless($account->isPublishable())<x-badge :value="$account->status" />@endunless</label>
                        @empty
                            <p class="text-slate-500">Connect an account first.</p>
                        @endforelse
                    </div>
                    <div class="flex flex-wrap items-end gap-3">
                        <div>
                            <label class="label">When ({{ \App\Support\DisplayTime::timezone() }}) — empty = now</label>
                            <input type="datetime-local" name="scheduled_at" class="input">
                        </div>
                        <label class="flex items-center gap-2"><input type="checkbox" name="allow_reuse" value="1"> Post again even if already posted</label>
                        <button class="btn btn-primary">Schedule</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="border-b border-slate-100 px-5 py-3"><h2 class="card-title">Posts of this video</h2></div>
                <table class="table">
                    <thead><tr><th>Platform</th><th>Scheduled</th><th>Status</th><th>Link</th></tr></thead>
                    <tbody>
                    @forelse($video->scheduledPosts->sortByDesc('scheduled_at') as $post)
                        <tr>
                            <td><x-platform :platform="$post->platform" /> {{ $post->socialAccount->name }}</td>
                            <td><a href="{{ route('posts.show', $post) }}" class="link"><x-time :at="$post->scheduled_at" /></a></td>
                            <td><x-badge :value="$post->status" /></td>
                            <td>@if($post->post_url)<a href="{{ $post->post_url }}" target="_blank" rel="noopener" class="link">Open ↗</a>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-slate-500">Not scheduled anywhere yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="card">
                <div class="border-b border-slate-100 px-5 py-3"><h2 class="card-title">AI generation history</h2></div>
                <table class="table">
                    <thead><tr><th>When</th><th>Status</th><th>Model</th><th>Tokens</th><th>Cost</th></tr></thead>
                    <tbody>
                    @forelse($video->aiGenerations as $gen)
                        <tr>
                            <td><x-time :at="$gen->created_at" /></td>
                            <td><x-badge :value="$gen->status" />@if($gen->error)<div class="mt-1 max-w-xs text-xs text-red-600">{{ \Illuminate\Support\Str::limit($gen->error, 160) }}</div>@endif</td>
                            <td class="text-xs">{{ $gen->provider }} / {{ $gen->model }} · {{ $gen->prompt_version }}</td>
                            <td class="text-xs">{{ $gen->input_tokens ?? '—' }} / {{ $gen->output_tokens ?? '—' }}</td>
                            <td class="text-xs">{{ $gen->cost_usd !== null ? '$'.number_format($gen->cost_usd, 4) : 'n/a' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-slate-500">No AI runs yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layout>
