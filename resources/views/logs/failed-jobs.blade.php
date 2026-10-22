<x-layout title="Failed jobs">
    <x-slot:header>
        <h1 class="text-xl font-semibold">Failed queue jobs</h1>
        @if(config('reelflow.queue_mode') !== 'cron')<a href="{{ url(config('horizon.path', 'horizon')) }}" class="btn">Open Horizon ↗</a>@endif
    </x-slot:header>
    <p class="mb-4 text-sm text-slate-500">These are background jobs (uploads, processing, AI, emails) that exhausted their retries. Publishing failures are listed under <a class="link" href="{{ route('posts.failed') }}">Failed posts</a>.</p>
    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Failed at</th><th>Queue</th><th>Job</th><th>Error</th><th></th></tr></thead>
            <tbody>
            @forelse($jobs as $job)
                @php($payload = json_decode($job->payload, true))
                <tr>
                    <td class="text-xs whitespace-nowrap">{{ \App\Support\DisplayTime::format(\Carbon\Carbon::parse($job->failed_at)) }}</td>
                    <td class="text-xs">{{ $job->connection }}:{{ $job->queue }}</td>
                    <td class="text-xs">{{ class_basename($payload['displayName'] ?? 'unknown') }}</td>
                    <td class="max-w-lg text-xs text-red-600">{{ \App\Support\SecretRedactor::truncate(strtok($job->exception, "\n"), 300) }}</td>
                    <td class="text-right whitespace-nowrap">
                        <form method="POST" action="{{ route('logs.failed-jobs.retry', $job->uuid) }}" class="inline">@csrf<button class="btn btn-sm">Retry</button></form>
                        <form method="POST" action="{{ route('logs.failed-jobs.forget', $job->uuid) }}" class="inline" data-confirm="Delete this failed job record?">@csrf @method('DELETE')<button class="btn btn-sm">Delete</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-6 text-center text-slate-500">No failed jobs.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $jobs->links() }}</div>
</x-layout>
