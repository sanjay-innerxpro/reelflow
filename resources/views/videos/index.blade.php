<x-layout title="Video library">
    <x-slot:header>
        <div>
            <h1>Video library</h1>
            <p class="mt-1 text-sm text-slate-500">{{ number_format($videos->total()) }} {{ \Illuminate\Support\Str::plural('video', $videos->total()) }}{{ array_filter($filters) ? ' matching your filters' : '' }}</p>
        </div>
        <a href="{{ route('uploads.index') }}" class="btn btn-primary"><x-icon name="upload" class="h-4 w-4" /> Upload videos</a>
    </x-slot:header>

    <form method="GET" class="card mb-4 p-2.5">
        <div class="grid gap-2 lg:grid-cols-12">
            <div class="relative lg:col-span-3">
                <x-icon name="list" class="pointer-events-none absolute top-2.5 left-3 h-4 w-4 text-slate-400" />
                <input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search file name, folder, topic…" class="input pl-9" aria-label="Search">
            </div>
            <select name="folder" class="input lg:col-span-2" aria-label="Folder">
                <option value="">All folders</option>
                @foreach($folders as $folder)
                    <option value="{{ $folder }}" @selected(($filters['folder'] ?? '') === $folder)>{{ $folder ?: '(root)' }}</option>
                @endforeach
            </select>
            <select name="status" class="input lg:col-span-2" aria-label="Status">
                <option value="">Any status</option>
                @foreach(\App\Enums\VideoStatus::cases() as $s)
                    <option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ ucfirst($s->value) }}</option>
                @endforeach
            </select>
            <select name="ai" class="input lg:col-span-2" aria-label="AI captions">
                <option value="">Any captions</option>
                @foreach(\App\Enums\AiStatus::cases() as $s)
                    <option value="{{ $s->value }}" @selected(($filters['ai'] ?? '') === $s->value)>Captions: {{ str_replace('_', ' ', $s->value) }}</option>
                @endforeach
            </select>
            <select name="posted" class="input lg:col-span-2" aria-label="Posted">
                <option value="">Posted or not</option>
                <option value="never" @selected(($filters['posted'] ?? '') === 'never')>Never scheduled</option>
                <option value="any" @selected(($filters['posted'] ?? '') === 'any')>Scheduled / posted</option>
            </select>
            <div class="flex gap-2 lg:col-span-1">
                <button class="btn btn-primary w-full" title="Apply filters">Apply</button>
            </div>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
            <label class="flex items-center gap-2"><input type="checkbox" name="duplicates" value="1" @checked($filters['duplicates'] ?? false)> Only files found in several local paths</label>
            @if(array_filter($filters))<a href="{{ route('videos.index') }}" class="link">Clear filters</a>@endif
        </div>
    </form>

    <form method="POST" action="{{ route('videos.bulk') }}">
        @csrf
        @if($videos->isNotEmpty())
            <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <label class="flex items-center gap-2 font-medium text-slate-700"><input type="checkbox" data-select-all> Select all</label>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600"><span data-selected-count>0</span> selected</span>
                <div class="ml-auto flex gap-2">
                    <select name="action" class="input h-9 w-auto py-1.5" aria-label="Bulk action">
                        <option value="generate">Generate / regenerate captions</option>
                        <option value="reprocess">Re-run metadata processing</option>
                        <option value="allow_reuse">Allow re-posting</option>
                        <option value="disallow_reuse">Disallow re-posting</option>
                    </select>
                    <button class="btn">Apply to selected</button>
                </div>
            </div>
        @endif

        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 2xl:grid-cols-7">
            @forelse($videos as $video)
                @php
                    $needsEye = in_array($video->ai_status->value, ['failed', 'low_confidence'], true);
                @endphp
                <div class="group card relative overflow-hidden p-0 transition hover:-translate-y-0.5 hover:shadow-(--shadow-lift)">
                    <a href="{{ route('videos.show', $video) }}" class="relative block aspect-[9/16] overflow-hidden bg-slate-100">
                        @if($video->thumbnail_key)
                            <img src="{{ route('videos.thumbnail', $video) }}" alt="" loading="lazy" class="h-full w-full object-cover transition duration-300 group-hover:scale-105">
                        @else
                            <div class="flex h-full flex-col items-center justify-center gap-2 text-xs text-slate-400"><x-icon name="photo" class="h-8 w-8" /> {{ $video->status->value === 'ready' ? 'No thumbnail' : ucfirst($video->status->value).'…' }}</div>
                        @endif
                        {{-- Everything readable sits on the image, so the card is as
                             tall as the reel and no taller. --}}
                        <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/85 via-black/45 to-transparent pt-8 pb-2 pl-2.5 pr-2.5">
                            <div class="truncate text-[12px] font-medium text-on-color" title="{{ $video->original_filename }}">{{ $video->original_filename }}</div>
                            <div class="mt-0.5 flex items-center gap-1 truncate text-[11px] text-on-color/70" title="{{ $video->folder }}">
                                <x-icon name="folder" class="h-3 w-3 shrink-0" /> <span class="truncate">{{ $video->folder ?: 'No folder' }}</span>
                            </div>
                        </div>
                        @if($video->duration_seconds)
                            <span class="absolute top-2 right-2 rounded-md bg-black/55 px-1.5 py-0.5 text-[11px] font-medium text-on-color backdrop-blur">{{ gmdate($video->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', (int) $video->duration_seconds) }}</span>
                        @endif
                        <div class="absolute top-2 left-8 flex flex-wrap gap-1">
                            @if($video->status->value !== 'ready')
                                <x-badge :value="$video->status" />
                            @elseif($needsEye)
                                <x-badge :value="$video->ai_status">{{ $video->ai_status->value === 'failed' ? 'no captions' : 'needs review' }}</x-badge>
                            @endif
                            @if($video->sources_count > 1)<x-badge color="gray">{{ $video->sources_count }}×</x-badge>@endif
                        </div>
                        @if($video->scheduled_posts_count)
                            <span class="absolute right-2 bottom-2 rounded-md bg-indigo-600/90 px-1.5 py-0.5 text-[11px] font-medium text-on-color">{{ $video->scheduled_posts_count }} post{{ $video->scheduled_posts_count > 1 ? 's' : '' }}</span>
                        @endif
                    </a>
                    <input type="checkbox" name="ids[]" value="{{ $video->id }}" data-select-item class="absolute top-2.5 left-2.5 h-4 w-4 rounded border-white/70 bg-white/80 shadow" aria-label="Select {{ $video->original_filename }}">
                </div>
            @empty
                <div class="col-span-full flex flex-col items-center rounded-2xl border-2 border-dashed border-slate-200 px-6 py-16 text-center">
                    <span class="brand-gradient flex h-14 w-14 items-center justify-center rounded-2xl text-on-color shadow-(--shadow-lift)"><x-icon name="video" class="h-7 w-7" /></span>
                    <h2 class="mt-4 text-base font-semibold text-slate-900">{{ array_filter($filters) ? 'No videos match these filters' : 'Your library is empty' }}</h2>
                    <p class="mt-1 max-w-md text-sm text-slate-500">Upload a ZIP of reels, import a Google Drive folder, or run the sync agent on your computer.</p>
                    <div class="mt-5 flex flex-wrap justify-center gap-2">
                        <a href="{{ route('uploads.index') }}" class="btn btn-primary"><x-icon name="upload" class="h-4 w-4" /> Upload videos</a>
                        <a href="{{ route('drive.index') }}" class="btn"><x-icon name="cloud" class="h-4 w-4" /> Import from Drive</a>
                    </div>
                </div>
            @endforelse
        </div>
    </form>

    <div class="mt-6">{{ $videos->links() }}</div>
</x-layout>
