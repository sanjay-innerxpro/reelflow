@php
    use App\Models\AiGeneration;
    use Illuminate\Support\Number;
    use Illuminate\Support\Str;

    $aiSeries = [
        AiGeneration::STATUS_SUCCEEDED => ['label' => 'Succeeded', 'mark' => 'good'],
        AiGeneration::STATUS_LOW_CONFIDENCE => ['label' => 'Needs review', 'mark' => 'warn'],
        AiGeneration::STATUS_FAILED => ['label' => 'Failed', 'mark' => 'bad'],
    ];
    $postSeries = [
        'published' => ['label' => 'Published', 'mark' => 'good'],
        'pending' => ['label' => 'Scheduled', 'mark' => 'warn'],
        'failed' => ['label' => 'Failed', 'mark' => 'bad'],
    ];
@endphp

<x-layout title="Overview">
    <x-slot:header>
        <div class="page-header">
            <h1>Overview</h1>
            <p class="help">Every workspace on this install.</p>
        </div>
        <form method="GET">
            <select name="days" class="input w-auto" onchange="this.form.submit()" aria-label="Time range">
                @foreach($ranges as $r)<option value="{{ $r }}" @selected($days === $r)>Last {{ $r }} days</option>@endforeach
            </select>
        </form>
    </x-slot:header>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-stat label="Users" :value="$users" icon="users"
                :hint="$workspaces.' workspace'.($workspaces === 1 ? '' : 's').($suspended ? ' · '.$suspended.' suspended' : '')"
                :href="route('admin.users.index')" />
        <x-stat label="Videos" :value="$videos" icon="video" :hint="Number::fileSize($storageUsed, 1).' stored'" />
        <x-stat label="Published" :value="$published" icon="send" :hint="'in the last '.$days.' days'" />
        <div class="card card-body">
            <div class="flex items-start justify-between gap-3">
                <div class="text-sm font-medium text-slate-500">AI captions usable</div>
                <span class="flex h-9 w-9 items-center justify-center rounded-xl {{ $aiSuccessRate !== null && $aiSuccessRate < 80 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600' }}">
                    <x-icon name="sparkles" class="h-5 w-5" />
                </span>
            </div>
            <div class="mt-2 text-3xl font-semibold tracking-tight {{ $aiSuccessRate !== null && $aiSuccessRate < 80 ? 'text-red-600' : 'text-slate-900' }}">
                {{ $aiSuccessRate !== null ? $aiSuccessRate.'%' : 'n/a' }}
            </div>
            <div class="mt-1 text-xs text-slate-500">
                {{ $aiFinished }} finished{{ $aiRunning ? ' · '.$aiRunning.' running' : '' }} in the last {{ $days }} days
            </div>
        </div>
    </div>

    <div class="card mt-6">
        <div class="card-header">
            <h2 class="card-title">Caption generation</h2>
            <span class="text-xs text-slate-500">per day</span>
        </div>
        <div class="card-body">
            <x-chart.stacked-bars :rows="$aiPerDay" :series="$aiSeries"
                caption="Caption generations per day by outcome"
                empty="No captions have been generated in this period." />
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card">
            <div class="card-header"><h2 class="card-title">Why generations failed</h2></div>
            <div class="card-body">
                <x-chart.bars :rows="$aiFailures" empty="No failures in this period." />
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="card-title">Publishing by platform</h2></div>
            <div class="card-body">
                <x-chart.stacked-bars :rows="$publishing" :series="$postSeries"
                    caption="Scheduled posts per platform by outcome"
                    empty="No posts in this period." />
            </div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Storage per workspace</h2>
                <a class="link text-xs" href="{{ route('admin.users.index') }}">Manage limits</a>
            </div>
            <div class="card-body">
                <x-chart.bars :rows="$storagePerWorkspace" :format="fn ($v) => Number::fileSize($v, 1)"
                    empty="Nothing stored yet." />
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Caption outcomes in numbers</h2>
            </div>
            <div class="overflow-x-auto">
                {{-- The table is the accessible reading of the charts above. --}}
                <table class="table">
                    <thead><tr><th>Day</th><th>Succeeded</th><th>Needs review</th><th>Failed</th></tr></thead>
                    <tbody>
                    @foreach(array_reverse($aiPerDay) as $row)
                        @continue(array_sum($row['values']) === 0)
                        <tr>
                            <td>{{ $row['label'] ?: '—' }}</td>
                            @foreach($aiSeries as $key => $s)
                                <td class="tabular-nums">{{ $row['values'][$key] }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if(collect($aiPerDay)->sum(fn ($r) => array_sum($r['values'])) === 0)
                        <tr><td colspan="4" class="py-6 text-center text-slate-500">Nothing yet.</td></tr>
                    @endif
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-6">
        <div class="card-header">
            <h2 class="card-title">Latest failed generations</h2>
            <span class="text-xs text-slate-500">what the model actually returned</span>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>When</th><th>Workspace</th><th>Video</th><th>Model</th><th>Reason</th><th>Raw answer</th></tr></thead>
                <tbody>
                @forelse($recentFailures as $generation)
                    @php([$bucket, $fix] = \App\Http\Controllers\Admin\OverviewController::classify((string) $generation->error))
                    <tr>
                        <td class="text-xs whitespace-nowrap"><x-time :at="$generation->completed_at ?? $generation->created_at" /></td>
                        <td class="text-xs">{{ $generation->workspace?->name ?? '—' }}</td>
                        <td class="text-xs">
                            @if($generation->video)
                                <a class="link" href="{{ route('videos.show', $generation->video) }}">{{ Str::limit($generation->video->original_filename, 28) }}</a>
                            @else — @endif
                        </td>
                        <td class="text-xs">{{ $generation->provider }}/{{ Str::limit($generation->model, 22) }}</td>
                        <td class="max-w-xs text-xs">
                            <div class="flex items-center gap-1.5 font-medium"><span class="swatch swatch-bad"></span>{{ $bucket }}</div>
                            <div class="mt-0.5 text-slate-500">{{ Str::limit($generation->error, 140) }}</div>
                            <div class="mt-0.5 text-[11px] text-slate-500">{{ $fix }}</div>
                        </td>
                        <td class="max-w-sm">
                            @if($generation->raw_output)
                                <details>
                                    <summary class="cursor-pointer text-xs text-slate-500 hover:text-slate-700">Show answer</summary>
                                    <pre class="mt-1 max-h-48 overflow-auto rounded-lg bg-slate-50 p-2 text-[11px] whitespace-pre-wrap">{{ $generation->raw_output }}</pre>
                                </details>
                            @else
                                <span class="text-xs text-slate-400">not recorded</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-6 text-center text-slate-500">No failed generations. </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layout>
