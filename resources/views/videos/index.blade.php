<x-layout title="Video library">
    <x-slot:header>
        <h1 class="text-xl font-semibold">Video library <span class="text-base font-normal text-slate-500">({{ $videos->total() }})</span></h1>
    </x-slot:header>

    <form method="GET" class="card card-body mb-4 grid gap-3 md:grid-cols-6">
        <div class="md:col-span-2">
            <label class="label">Search</label>
            <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="File name, folder, topic…" class="input">
        </div>
        <div>
            <label class="label">Folder</label>
            <select name="folder" class="input">
                <option value="">All folders</option>
                @foreach($folders as $folder)
                    <option value="{{ $folder }}" @selected(($filters['folder'] ?? '') === $folder)>{{ $folder ?: '(root)' }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">Status</label>
            <select name="status" class="input">
                <option value="">Any</option>
                @foreach(\App\Enums\VideoStatus::cases() as $s)
                    <option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ ucfirst($s->value) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">AI captions</label>
            <select name="ai" class="input">
                <option value="">Any</option>
                @foreach(\App\Enums\AiStatus::cases() as $s)
                    <option value="{{ $s->value }}" @selected(($filters['ai'] ?? '') === $s->value)>{{ str_replace('_', ' ', ucfirst($s->value)) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label">Posted</label>
            <select name="posted" class="input">
                <option value="">Any</option>
                <option value="never" @selected(($filters['posted'] ?? '') === 'never')>Never scheduled</option>
                <option value="any" @selected(($filters['posted'] ?? '') === 'any')>Scheduled/posted</option>
            </select>
        </div>
        <div class="flex items-center gap-3 md:col-span-6">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="duplicates" value="1" @checked($filters['duplicates'] ?? false)> Only files found in several local paths</label>
            <button class="btn btn-primary ml-auto">Filter</button>
            <a href="{{ route('videos.index') }}" class="btn">Reset</a>
        </div>
    </form>

    <form method="POST" action="{{ route('videos.bulk') }}">
        @csrf
        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <label class="flex items-center gap-2"><input type="checkbox" data-select-all> Select all</label>
            <span class="text-slate-500"><span data-selected-count>0</span> selected</span>
            <select name="action" class="input w-auto">
                <option value="generate">Generate / regenerate captions</option>
                <option value="reprocess">Re-run metadata processing</option>
                <option value="allow_reuse">Allow re-posting</option>
                <option value="disallow_reuse">Disallow re-posting</option>
            </select>
            <button class="btn">Apply</button>
        </div>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
            @forelse($videos as $video)
                <div class="card overflow-hidden">
                    <div class="relative aspect-[9/16] bg-slate-100">
                        @if($video->thumbnail_key)
                            <img src="{{ route('videos.thumbnail', $video) }}" alt="" loading="lazy" class="h-full w-full object-cover">
                        @else
                            <div class="flex h-full items-center justify-center text-xs text-slate-400">No thumbnail</div>
                        @endif
                        <input type="checkbox" name="ids[]" value="{{ $video->id }}" data-select-item class="absolute top-2 left-2 h-4 w-4">
                        @if($video->duration_seconds)
                            <span class="absolute right-2 bottom-2 rounded bg-black/70 px-1 text-xs text-white">{{ gmdate($video->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', (int) $video->duration_seconds) }}</span>
                        @endif
                    </div>
                    <a href="{{ route('videos.show', $video) }}" class="block space-y-1 p-2">
                        <div class="truncate text-sm font-medium" title="{{ $video->original_filename }}">{{ $video->original_filename }}</div>
                        <div class="truncate text-xs text-slate-500" title="{{ $video->folder }}">{{ $video->folder ?: '—' }}</div>
                        <div class="flex flex-wrap gap-1">
                            <x-badge :value="$video->status" />
                            <x-badge :value="$video->ai_status">AI: {{ str_replace('_', ' ', $video->ai_status->value) }}</x-badge>
                            @if($video->scheduled_posts_count)<x-badge color="indigo">{{ $video->scheduled_posts_count }} post(s)</x-badge>@endif
                            @if($video->sources_count > 1)<x-badge color="gray">{{ $video->sources_count }} copies</x-badge>@endif
                        </div>
                    </a>
                </div>
            @empty
                <div class="col-span-full rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500">
                    No videos yet. Register a <a href="{{ route('devices.index') }}" class="link">sync device</a> and point the agent at your video folders.
                </div>
            @endforelse
        </div>
    </form>

    <div class="mt-6">{{ $videos->links() }}</div>
</x-layout>
