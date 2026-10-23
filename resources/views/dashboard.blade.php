<x-layout title="Dashboard">
    @php
        $hour = (int) now(\App\Support\DisplayTime::timezone())->format('G');
        $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
        $firstName = \Illuminate\Support\Str::before(auth()->user()->name, ' ');
        $steps = [
            ['done' => $onboarding['account'], 'title' => 'Connect Facebook or Instagram', 'text' => 'Link the Page or professional account you want to post to.', 'href' => route('accounts.index'), 'cta' => 'Connect account', 'icon' => 'link'],
            ['done' => $onboarding['videos'], 'title' => 'Upload your reels', 'text' => 'Drop a ZIP of up to 20 videos. Captions are written for you.', 'href' => route('uploads.index'), 'cta' => 'Upload videos', 'icon' => 'upload'],
            ['done' => $onboarding['autopost'], 'title' => 'Turn on auto-posting', 'text' => 'Pick your posting times; ReelFlow publishes on schedule.', 'href' => route('rules.index'), 'cta' => 'Set posting times', 'icon' => 'calendar'],
        ];
        $doneSteps = collect($steps)->where('done', true)->count();
    @endphp

    <x-slot:header>
        <div>
            <h1>{{ $greeting }}, {{ $firstName }}</h1>
            <p class="mt-1 text-sm text-slate-500">Here's what's happening with your reels · times in {{ \App\Support\DisplayTime::timezone() }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('accounts.index') }}" class="btn"><x-icon name="link" class="h-4 w-4" /> Accounts</a>
            <a href="{{ route('uploads.index') }}" class="btn btn-primary"><x-icon name="upload" class="h-4 w-4" /> Upload videos</a>
        </div>
    </x-slot:header>

    @if($setup)
        <div class="card mb-6 {{ collect($setup)->contains('status', 'fail') ? 'border-red-200' : 'border-amber-200' }}">
            <div class="card-header">
                <h2 class="card-title flex items-center gap-2"><x-icon name="shield" class="h-4 w-4 text-amber-500" /> Server setup <span class="font-normal text-slate-500">(admin)</span></h2>
                <span class="text-xs text-slate-500">Full report: <code>php artisan reels:doctor</code></span>
            </div>
            <ul class="divide-y divide-slate-100 text-sm">
                @foreach($setup as $check)
                    <li class="flex flex-wrap gap-x-3 gap-y-1 px-5 py-2.5">
                        <x-badge :color="$check['status'] === 'fail' ? 'red' : 'amber'">{{ $check['status'] === 'fail' ? 'action needed' : 'to do' }}</x-badge>
                        <span class="w-52 shrink-0 font-medium">{{ $check['label'] }}</span>
                        <span class="text-slate-600">{{ $check['message'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($doneSteps < count($steps))
        <div class="card relative mb-6 overflow-hidden">
            <div class="brand-gradient pointer-events-none absolute -top-24 -right-24 h-64 w-64 rounded-full opacity-10 blur-2xl"></div>
            <div class="card-body">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Get started</h2>
                        <p class="text-sm text-slate-500">Three steps to fully automatic posting.</p>
                    </div>
                    <div class="flex items-center gap-3 text-sm text-slate-500">
                        {{ $doneSteps }} of {{ count($steps) }} done
                        <div class="h-2 w-28 overflow-hidden rounded-full bg-slate-100"><div class="brand-gradient h-full rounded-full" style="width: {{ round($doneSteps / count($steps) * 100) }}%"></div></div>
                    </div>
                </div>
                <ol class="mt-5 grid gap-3 md:grid-cols-3">
                    @foreach($steps as $i => $step)
                        <li class="flex flex-col rounded-xl border p-4 {{ $step['done'] ? 'border-emerald-200 bg-emerald-50/60' : 'border-slate-200 bg-white' }}">
                            <div class="flex items-center gap-3">
                                @if($step['done'])
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-emerald-500 text-on-color"><x-icon name="check" class="h-5 w-5" /></span>
                                @else
                                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-50 text-indigo-600"><x-icon :name="$step['icon']" class="h-5 w-5" /></span>
                                @endif
                                <span class="text-xs font-semibold tracking-wider text-slate-400 uppercase">Step {{ $i + 1 }}</span>
                            </div>
                            <div class="mt-3 font-medium text-slate-900">{{ $step['title'] }}</div>
                            <p class="mt-1 flex-1 text-sm text-slate-500">{{ $step['text'] }}</p>
                            @unless($step['done'])
                                <a href="{{ $step['href'] }}" class="btn btn-sm mt-3 self-start">{{ $step['cta'] }} <x-icon name="arrow-right" class="h-3.5 w-3.5" /></a>
                            @endunless
                        </li>
                    @endforeach
                </ol>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-2 gap-4 xl:grid-cols-4">
        <x-stat label="Ready to post" :value="$ready" :hint="$stats['awaiting'] ? $stats['awaiting'].' still processing' : $stats['videos'].' videos in library'" :href="route('videos.index', ['status' => 'ready'])" icon="video" />
        <x-stat label="Scheduled" :value="$stats['scheduled']" :hint="$stats['retrying'] ? $stats['retrying'].' in progress' : 'upcoming posts'" :href="route('calendar')" icon="calendar" />
        <x-stat label="Published today" :value="$stats['published_today']" hint="across all accounts" :href="route('posts.index', ['status' => 'published'])" icon="bolt" tone="green" />
        <x-stat label="Needs attention" :value="$stats['failed']" :hint="$stats['failed'] ? 'failed posts to review' : 'all good'" :href="route('posts.failed')" icon="warning" :tone="$stats['failed'] ? 'red' : 'slate'" />
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="card lg:col-span-2">
            <div class="card-header">
                <h2 class="card-title">Up next</h2>
                <a href="{{ route('calendar') }}" class="link text-sm">Calendar →</a>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse($upcoming as $post)
                    <li class="flex items-center gap-4 px-5 py-3">
                        <div class="h-14 w-8 shrink-0 overflow-hidden rounded-md bg-slate-100">
                            @if($post->video->thumbnail_key)<img src="{{ route('videos.thumbnail', $post->video) }}" alt="" loading="lazy" class="h-full w-full object-cover">@endif
                        </div>
                        <div class="min-w-0 flex-1">
                            <a href="{{ route('videos.show', $post->video) }}" class="block truncate text-sm font-medium text-slate-900 hover:text-indigo-600">{{ $post->video->original_filename }}</a>
                            <div class="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500"><x-platform :platform="$post->platform" /> {{ $post->socialAccount->name }}</div>
                        </div>
                        <div class="text-right text-sm font-medium whitespace-nowrap text-slate-700"><x-time :at="$post->scheduled_at" /></div>
                    </li>
                @empty
                    <li class="flex flex-col items-center px-5 py-12 text-center">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><x-icon name="calendar" class="h-6 w-6" /></span>
                        <p class="mt-3 text-sm font-medium text-slate-900">Nothing scheduled yet</p>
                        <p class="mt-1 max-w-sm text-sm text-slate-500">Once videos are ready, turn on a <a class="link" href="{{ route('rules.index') }}">posting rule</a> and the calendar fills itself.</p>
                    </li>
                @endforelse
            </ul>
        </div>

        <div class="space-y-6">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Accounts</h2>
                    <a href="{{ route('accounts.index') }}" class="link text-sm">Manage</a>
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse($accounts as $account)
                        <li class="flex items-center justify-between gap-2 px-5 py-3">
                            <span class="flex min-w-0 items-center gap-2.5"><x-platform :platform="$account->platform" class="h-7 w-7 rounded-full text-xs" /> <span class="truncate font-medium text-slate-800">{{ $account->name }}</span></span>
                            <span class="flex shrink-0 items-center gap-1">
                                @if($account->postingRule?->enabled)<x-badge color="indigo">auto</x-badge>@endif
                                <x-badge :value="$account->paused_until?->isFuture() ? 'paused' : $account->status" />
                            </span>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-slate-500">
                            No accounts yet.
                            <a href="{{ route('accounts.index') }}" class="link mt-1 block">Connect Facebook / Instagram →</a>
                        </li>
                    @endforelse
                </ul>
            </div>

            @if($devices->isNotEmpty())
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">Sync agents</h2>
                        <a href="{{ route('devices.index') }}" class="link text-sm">Manage</a>
                    </div>
                    <ul class="divide-y divide-slate-100 text-sm">
                        @foreach($devices as $device)
                            <li class="flex items-center justify-between px-5 py-3">
                                <span class="font-medium text-slate-800">{{ $device->name }}</span>
                                <span class="text-right">
                                    <x-badge :value="$device->isOnline() ? 'online' : 'offline'" />
                                    <span class="block text-xs text-slate-400">seen <x-time :at="$device->last_seen_at" /></span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if($queues)
                <div class="card card-body text-sm">
                    <h2 class="card-title mb-3">Queue health <span class="font-normal text-slate-500">(admin)</span></h2>
                    <div class="flex items-center justify-between py-0.5"><span class="text-slate-600">Horizon</span><x-badge :value="$queues['horizon'] === 'running' ? 'active' : $queues['horizon']" /></div>
                    <div class="flex items-center justify-between py-0.5"><span class="text-slate-600">Failed jobs</span><a class="link" href="{{ route('logs.failed-jobs') }}">{{ $queues['failed_jobs'] }}</a></div>
                    <div class="mt-2 grid grid-cols-2 gap-x-4 text-xs text-slate-500">
                        @foreach($queues['sizes'] as $queue => $size)
                            <span>{{ $queue }}: {{ $size ?? 'n/a' }}</span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    <div class="card mt-6">
        <div class="card-header">
            <h2 class="card-title">Recent activity</h2>
            <a href="{{ route('logs.index') }}" class="link text-sm">All activity →</a>
        </div>
        <ul class="divide-y divide-slate-100 text-sm">
            @forelse($activity as $log)
                <li class="flex items-center gap-3 px-5 py-2.5">
                    <span @class(['h-2 w-2 shrink-0 rounded-full', 'bg-red-500' => $log->level === 'error', 'bg-amber-500' => $log->level === 'warning', 'bg-slate-300' => ! in_array($log->level, ['error', 'warning'])])></span>
                    <span class="min-w-0 flex-1 truncate text-slate-700">{{ $log->message }}</span>
                    @if(auth()->user()->isAdmin())<span class="hidden font-mono text-xs text-slate-400 md:inline">{{ $log->action }}</span>@endif
                    <span class="shrink-0 text-xs text-slate-400"><x-time :at="$log->created_at" /></span>
                </li>
            @empty
                <li class="px-5 py-6 text-center text-slate-500">No activity yet.</li>
            @endforelse
        </ul>
    </div>
</x-layout>
