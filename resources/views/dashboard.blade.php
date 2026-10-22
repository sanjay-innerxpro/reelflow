<x-layout title="Dashboard">
    <x-slot:header>
        <h1 class="text-xl font-semibold">Dashboard</h1>
        <div class="text-sm text-slate-500">Times shown in {{ \App\Support\DisplayTime::timezone() }}</div>
    </x-slot:header>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <x-stat label="Total videos" :value="$stats['videos']" :href="route('videos.index')" />
        <x-stat label="Awaiting processing" :value="$stats['awaiting']" :href="route('videos.index', ['status' => 'processing'])" tone="amber" />
        <x-stat label="Scheduled posts" :value="$stats['scheduled']" :href="route('calendar')" />
        <x-stat label="Published today" :value="$stats['published_today']" :href="route('posts.index', ['status' => 'published'])" tone="green" />
        <x-stat label="In progress / retrying" :value="$stats['retrying']" :href="route('posts.index', ['status' => 'retrying'])" tone="amber" />
        <x-stat label="Failed posts" :value="$stats['failed']" :href="route('posts.failed')" :tone="$stats['failed'] ? 'red' : 'slate'" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                <h2 class="card-title">Up next</h2>
                <a href="{{ route('calendar') }}" class="link text-sm">Calendar →</a>
            </div>
            <table class="table">
                <tbody>
                @forelse($upcoming as $post)
                    <tr>
                        <td class="w-32 whitespace-nowrap"><x-time :at="$post->scheduled_at" /></td>
                        <td><x-platform :platform="$post->platform" /> <span class="text-slate-500">{{ $post->socialAccount->name }}</span></td>
                        <td><a href="{{ route('videos.show', $post->video) }}" class="link">{{ \Illuminate\Support\Str::limit($post->video->original_filename, 50) }}</a></td>
                    </tr>
                @empty
                    <tr><td class="py-6 text-center text-slate-500">Nothing scheduled. Enable a <a class="link" href="{{ route('rules.index') }}">posting rule</a> once videos are ready.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="card-title">Connected accounts</h2>
                    <a href="{{ route('accounts.index') }}" class="link text-sm">Manage</a>
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($accounts as $account)
                        <li class="flex items-center justify-between gap-2 px-5 py-2">
                            <span class="flex min-w-0 items-center gap-2"><x-platform :platform="$account->platform" /> <span class="truncate">{{ $account->name }}</span></span>
                            <span class="flex items-center gap-1">
                                @if($account->postingRule?->enabled)<x-badge color="indigo">auto</x-badge>@endif
                                <x-badge :value="$account->paused_until?->isFuture() ? 'paused' : $account->status" />
                            </span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-slate-500">No accounts connected yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="card">
                <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                    <h2 class="card-title">Sync agents</h2>
                    <a href="{{ route('devices.index') }}" class="link text-sm">Manage</a>
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($devices as $device)
                        <li class="flex items-center justify-between px-5 py-2">
                            <span>{{ $device->name }}</span>
                            <span class="text-right">
                                <x-badge :value="$device->isOnline() ? 'online' : 'offline'" />
                                <span class="block text-xs text-slate-400">seen <x-time :at="$device->last_seen_at" /></span>
                            </span>
                        </li>
                    @empty
                        <li class="px-5 py-4 text-slate-500">No sync devices registered.</li>
                    @endforelse
                </ul>
            </div>

            <div class="card card-body text-sm">
                <h2 class="card-title mb-2">Queue health</h2>
                <div class="flex items-center justify-between"><span>Horizon</span><x-badge :value="$queues['horizon'] === 'running' ? 'active' : $queues['horizon']" /></div>
                <div class="flex items-center justify-between"><span>Failed jobs</span><a class="link" href="{{ route('logs.failed-jobs') }}">{{ $queues['failed_jobs'] }}</a></div>
                <div class="mt-2 grid grid-cols-2 gap-x-4 text-xs text-slate-500">
                    @foreach($queues['sizes'] as $queue => $size)
                        <span>{{ $queue }}: {{ $size ?? 'n/a' }}</span>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-6">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <h2 class="card-title">Recent activity</h2>
            <a href="{{ route('logs.index') }}" class="link text-sm">All logs →</a>
        </div>
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse($activity as $log)
                <li class="flex gap-3 px-5 py-2">
                    <span class="w-28 shrink-0 text-xs text-slate-400"><x-time :at="$log->created_at" /></span>
                    <x-badge :value="$log->level" />
                    <span class="font-mono text-xs text-slate-500">{{ $log->action }}</span>
                    <span class="min-w-0 truncate">{{ $log->message }}</span>
                </li>
            @empty
                <li class="px-5 py-4 text-slate-500">No activity yet.</li>
            @endforelse
        </ul>
    </div>
</x-layout>
