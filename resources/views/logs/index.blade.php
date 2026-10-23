<x-layout title="System logs">
    <x-slot:header>
        <h1 class="text-xl font-semibold">Activity &amp; audit log</h1>
        @if(auth()->user()->isAdmin())<a href="{{ route('logs.failed-jobs') }}" class="btn">Failed queue jobs</a>@endif
    </x-slot:header>

    <form method="GET" class="card card-body mb-4 flex flex-wrap items-end gap-3">
        <div><label class="label">Level</label>
            <select name="level" class="input"><option value="">All</option>
                @foreach(['info', 'warning', 'error'] as $l)<option value="{{ $l }}" @selected(($filters['level'] ?? '') === $l)>{{ ucfirst($l) }}</option>@endforeach
            </select></div>
        <div><label class="label">Action starts with</label><input name="action" value="{{ $filters['action'] ?? '' }}" class="input" placeholder="scheduledpost."></div>
        <button class="btn btn-primary">Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>When</th><th>Level</th><th>Action</th><th>Subject</th><th>Change</th><th>Message</th><th>Actor</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="text-xs whitespace-nowrap"><x-time :at="$log->created_at" format="d M H:i:s" /></td>
                    <td><x-badge :value="$log->level" /></td>
                    <td class="font-mono text-xs">{{ $log->action }}</td>
                    <td class="text-xs">{{ $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : '—' }}</td>
                    <td class="text-xs">{{ $log->from_status ? $log->from_status.' → '.$log->to_status : '' }}</td>
                    <td class="max-w-md text-xs">{{ $log->message }}</td>
                    <td class="text-xs">{{ $log->user?->name ?? $log->actor_type }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-6 text-center text-slate-500">No log entries.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
    <p class="help mt-2">Application error logs (with secrets redacted) are in storage/logs on the server.</p>
</x-layout>
