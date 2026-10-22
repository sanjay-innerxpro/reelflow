<x-layout title="Calendar">
    @php($tz = \App\Support\DisplayTime::timezone())
    <x-slot:header>
        <h1 class="text-xl font-semibold">Content calendar</h1>
        <form method="GET" class="flex flex-wrap items-center gap-2">
            <select name="view" class="input w-auto">
                <option value="week" @selected($mode === 'week')>Week</option>
                <option value="day" @selected($mode === 'day')>Day</option>
            </select>
            <input type="date" name="date" value="{{ $anchor->toDateString() }}" class="input w-auto">
            <select name="platform" class="input w-auto">
                <option value="">All platforms</option>
                @foreach(\App\Enums\Platform::cases() as $p)
                    <option value="{{ $p->value }}" @selected(($filters['platform'] ?? '') === $p->value)>{{ $p->label() }}</option>
                @endforeach
            </select>
            <button class="btn">Show</button>
            @php($step = $mode === 'week' ? 7 : 1)
            <a class="btn" href="{{ route('calendar', array_merge($filters, ['view' => $mode, 'date' => $anchor->subDays($step)->toDateString()])) }}">←</a>
            <a class="btn" href="{{ route('calendar', array_merge($filters, ['view' => $mode, 'date' => $anchor->addDays($step)->toDateString()])) }}">→</a>
        </form>
    </x-slot:header>

    @if($globalPaused)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">All publishing is paused. Resume it on the <a href="{{ route('rules.index') }}" class="link">Posting rules</a> page.</div>
    @endif

    <div class="card mb-6 overflow-x-auto">
        <table class="table">
            <thead><tr><th>Account</th><th>Queue</th>@foreach(array_keys($days) as $date)<th class="text-center">{{ \Carbon\Carbon::parse($date)->format('D d') }}</th>@endforeach<th></th></tr></thead>
            <tbody>
            @foreach($accounts as $account)
                <tr>
                    <td class="whitespace-nowrap"><x-platform :platform="$account->platform" /> {{ $account->name }}</td>
                    <td><x-badge :value="$account->paused_until?->isFuture() ? 'paused' : $account->status" /></td>
                    @foreach(array_keys($days) as $date)
                        @php($l = $limits[$account->id][$date])
                        <td class="text-center text-xs {{ $l['limit'] !== null && $l['used'] >= $l['limit'] ? 'font-semibold text-emerald-700' : 'text-slate-500' }}">{{ $l['used'] }}/{{ $l['limit'] ?? '–' }}</td>
                    @endforeach
                    <td class="text-right whitespace-nowrap">
                        @if($account->status === \App\Enums\AccountStatus::Connected && ! $account->paused_until?->isFuture())
                            <form method="POST" action="{{ route('accounts.pause', $account) }}" class="inline">@csrf<button class="btn btn-sm">Pause</button></form>
                        @elseif($account->status === \App\Enums\AccountStatus::Paused || $account->paused_until?->isFuture())
                            <form method="POST" action="{{ route('accounts.resume', $account) }}" class="inline">@csrf<button class="btn btn-sm">Resume</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    <div class="grid gap-3 {{ $mode === 'week' ? 'md:grid-cols-7' : '' }}">
        @foreach($days as $date => $posts)
            <div class="card min-h-40">
                <div class="border-b border-slate-100 px-3 py-2 text-sm font-semibold {{ $date === now($tz)->toDateString() ? 'text-indigo-700' : '' }}">{{ \Carbon\Carbon::parse($date)->format('l, d M') }}</div>
                <ul class="space-y-1 p-2">
                    @forelse($posts as $post)
                        <li class="rounded-lg border border-slate-100 p-2 text-xs">
                            <div class="flex items-center justify-between gap-1">
                                <span class="font-semibold">{{ $post->scheduled_at->copy()->setTimezone($tz)->format('H:i') }}</span>
                                <x-badge :value="$post->status" />
                            </div>
                            <div class="mt-1 flex items-center gap-1"><x-platform :platform="$post->platform" /> <span class="truncate">{{ $post->socialAccount->name }}</span></div>
                            <a href="{{ route('posts.show', $post) }}" class="link mt-1 block truncate">{{ $post->video->original_filename }}</a>
                            @if($post->status === \App\Enums\PostStatus::Scheduled)
                                <form method="POST" action="{{ route('posts.reschedule', $post) }}" class="mt-1 flex gap-1">
                                    @csrf @method('PATCH')
                                    <input type="datetime-local" name="scheduled_at" required class="input px-1 py-0.5 text-xs" value="{{ $post->scheduled_at->copy()->setTimezone($tz)->format('Y-m-d\TH:i') }}">
                                    <button class="btn btn-sm" title="Reschedule">↻</button>
                                </form>
                            @endif
                        </li>
                    @empty
                        <li class="p-2 text-xs text-slate-400">Nothing scheduled.</li>
                    @endforelse
                </ul>
            </div>
        @endforeach
    </div>
</x-layout>
