<x-layout title="Analytics">
    <x-slot:header>
        <h1 class="text-xl font-semibold">Analytics</h1>
        <form method="GET" class="flex gap-2">
            <select name="days" class="input w-auto" onchange="this.form.submit()">
                @foreach([7, 30, 90] as $d)<option value="{{ $d }}" @selected($days === $d)>Last {{ $d }} days</option>@endforeach
            </select>
        </form>
    </x-slot:header>

    <div class="grid gap-4 md:grid-cols-3">
        @foreach($outcomes as $platform => $o)
            <div class="card card-body">
                <div class="text-sm font-medium text-slate-500">{{ $platform }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $o['success_rate'] !== null ? $o['success_rate'].'%' : 'n/a' }}</div>
                <div class="text-xs text-slate-500">publish success rate · {{ $o['published'] }} published, {{ $o['failed'] }} failed</div>
            </div>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 md:grid-cols-3">
        <div class="card card-body">
            <div class="text-sm font-medium text-slate-500">AI generation cost</div>
            <div class="mt-1 text-2xl font-semibold">{{ $aiCostKnown ? '$'.number_format($aiCost, 2) : 'n/a' }}</div>
            <div class="text-xs text-slate-500">{{ $aiRuns }} runs · {{ number_format($aiTokens[0]) }} in / {{ number_format($aiTokens[1]) }} out tokens{{ $aiCostKnown < $aiRuns ? ' · cost unknown for '.($aiRuns - $aiCostKnown).' run(s)' : '' }}</div>
        </div>
        <div class="card card-body text-xs md:col-span-2">
            <div class="mb-1 text-sm font-medium text-slate-500">Metric availability (official APIs)</div>
            @foreach($availability as $platform => $fields)
                <div><strong>{{ $platform }}:</strong> {{ implode(', ', $fields) }}</div>
            @endforeach
            <p class="help">"n/a" means the platform or granted permissions do not provide the metric — it is not zero.</p>
        </div>
    </div>

    <div class="card mt-6 overflow-x-auto">
        <div class="border-b border-slate-100 px-5 py-3"><h2 class="card-title">Latest metrics per post</h2></div>
        <table class="table">
            <thead><tr><th>Post</th><th>Views</th><th>Reach</th><th>Likes</th><th>Comments</th><th>Shares</th><th>Saves</th><th>Avg watch</th><th>Captured</th></tr></thead>
            <tbody>
            @forelse($metrics as $m)
                <tr>
                    <td><x-platform :platform="$m->scheduledPost->platform" /> <a class="link" href="{{ route('posts.show', $m->scheduledPost) }}">{{ \Illuminate\Support\Str::limit($m->scheduledPost->video->original_filename, 32) }}</a></td>
                    @foreach(['views', 'reach', 'likes', 'comments', 'shares', 'saves'] as $f)
                        <td>{{ $m->$f !== null ? number_format($m->$f) : 'n/a' }}</td>
                    @endforeach
                    <td>{{ $m->avg_watch_seconds !== null ? $m->avg_watch_seconds.'s' : 'n/a' }}</td>
                    <td class="text-xs"><x-time :at="$m->captured_at" /></td>
                </tr>
            @empty
                <tr><td colspan="9" class="py-6 text-center text-slate-500">No metrics yet. They are collected every 6 hours for published posts where the API allows.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
