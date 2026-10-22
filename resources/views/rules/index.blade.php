<x-layout title="Posting rules">
    <x-slot:header><h1 class="text-xl font-semibold">Posting rules</h1></x-slot:header>

    <form method="POST" action="{{ route('rules.global') }}" class="card card-body mb-6 space-y-2 text-sm">
        @csrf
        <h2 class="card-title">Global publishing settings</h2>
        <label class="flex items-center gap-2"><input type="checkbox" name="cross_post_all" value="1" @checked($crossPost)> Post each video to <strong>every</strong> enabled account (off: each video goes to one destination only)</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="require_approval" value="1" @checked($requireApproval)> Approval mode — only captions I have approved are published</label>
        <label class="flex items-center gap-2"><input type="checkbox" name="paused" value="1" @checked($paused)> <span class="font-medium text-red-700">Pause all publishing</span> (emergency stop)</label>
        <button class="btn btn-sm">Save</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Account</th><th>Enabled</th><th>Per day</th><th>Window</th><th>Min gap</th><th>Next slots</th><th></th></tr></thead>
            <tbody>
            @forelse($accounts as $account)
                @php($rule = $account->postingRule)
                <tr>
                    <td class="whitespace-nowrap"><x-platform :platform="$account->platform" /> {{ $account->name }} @unless($account->isPublishable())<x-badge :value="$account->status" />@endunless</td>
                    <td>{!! $rule?->enabled ? '<span class="text-emerald-700">Yes</span>' : '<span class="text-slate-400">No</span>' !!}</td>
                    <td>{{ $rule?->daily_limit ?? '—' }}{{ $rule?->weekend_daily_limit !== null ? ' (wknd '.$rule->weekend_daily_limit.')' : '' }}</td>
                    <td class="text-xs">{{ $rule ? substr($rule->window_start, 0, 5).'–'.substr($rule->window_end, 0, 5).' '.$rule->timezone : '—' }}</td>
                    <td>{{ $rule ? $rule->min_interval_minutes.' min' : '—' }}</td>
                    <td class="max-w-sm text-xs text-slate-500">{{ implode(', ', array_slice($preview[$account->id] ?? [], 0, 8)) ?: '—' }}</td>
                    <td><a href="{{ route('rules.edit', $account) }}" class="btn btn-sm">Edit</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-500">Connect an account to create its posting rule.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help mt-3">The planner fills slots {{ config('reelflow.scheduling.planning_horizon_days') }} day(s) ahead every 15 minutes with ready videos whose captions are publishable. Times are local to each rule's timezone and stored in UTC.</p>
</x-layout>
