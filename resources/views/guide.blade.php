@php
    // One stage per step of the pipeline, in the order a video travels.
    $stages = [
        ['n' => 1, 'icon' => 'upload', 'title' => 'Add videos', 'summary' => 'Sync agent, browser upload or Google Drive.', 'route' => 'uploads.index'],
        ['n' => 2, 'icon' => 'video', 'title' => 'Processed', 'summary' => 'Length, size and thumbnail are read from the file.', 'route' => 'videos.index'],
        ['n' => 3, 'icon' => 'sparkles', 'title' => 'Captions written', 'summary' => 'AI writes a caption per platform.', 'route' => 'settings.ai'],
        ['n' => 4, 'icon' => 'check-circle', 'title' => 'You review', 'summary' => 'Edit anything; approve if approval is on.', 'route' => 'videos.index'],
        ['n' => 5, 'icon' => 'calendar', 'title' => 'Scheduled', 'summary' => 'Your posting rules pick the time slots.', 'route' => 'rules.index'],
        ['n' => 6, 'icon' => 'send', 'title' => 'Published', 'summary' => 'Posted by the official APIs, then measured.', 'route' => 'posts.index'],
    ];
@endphp

<x-layout title="User guide">
    <x-slot:header>
        <div class="page-header">
            <h1>User guide</h1>
            <p class="help">How a video gets from your folder to a published Reel.</p>
        </div>
    </x-slot:header>

    <div class="card card-body">
        <h2 class="card-title">The flow</h2>
        <p class="help">Each step runs on the server. Once a video is uploaded you can close your laptop.</p>

        <ol class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            @foreach($stages as $stage)
                <li class="relative">
                    <a href="{{ route($stage['route']) }}"
                       class="flex h-full flex-col gap-2 rounded-xl border border-slate-200 bg-slate-50/60 p-3.5 transition hover:border-indigo-300 hover:bg-white hover:shadow-(--shadow-card)">
                        <span class="flex items-center gap-2">
                            <span class="brand-gradient flex h-7 w-7 items-center justify-center rounded-lg text-xs font-semibold text-on-color">{{ $stage['n'] }}</span>
                            <x-icon :name="$stage['icon']" class="h-4 w-4 text-indigo-500" />
                        </span>
                        <span class="text-sm font-semibold text-slate-900">{{ $stage['title'] }}</span>
                        <span class="text-xs leading-relaxed text-slate-500">{{ $stage['summary'] }}</span>
                    </a>
                    {{-- The arrow is decoration: the numbers already carry the order. --}}
                    @if(! $loop->last)
                        <span aria-hidden="true" class="pointer-events-none absolute top-1/2 -right-2.5 hidden -translate-y-1/2 text-slate-300 xl:block">
                            <x-icon name="arrow-right" class="h-4 w-4" />
                        </span>
                    @endif
                </li>
            @endforeach
        </ol>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card card-body">
            <h2 class="card-title">1 · Get your videos in</h2>
            <p class="help">Three ways, and you can mix them:</p>
            <ul class="mt-2 space-y-2 text-sm text-slate-600">
                <li><strong class="text-slate-900">Upload page.</strong> Drop a video, or a ZIP of up to {{ config('reelflow.web_uploads.max_videos') }} videos ({{ config('reelflow.web_uploads.max_mb') }} MB max). Large files are sent in chunks, so a dropped connection resumes. <a class="link" href="{{ route('uploads.index') }}">Upload videos</a></li>
                <li><strong class="text-slate-900">Sync agent.</strong> Install it once on your computer and it watches a folder: anything you drop in is uploaded on its own. <a class="link" href="{{ route('devices.index') }}">Sync devices</a></li>
                <li><strong class="text-slate-900">Google Drive.</strong> Connect a folder and new files in it are imported. <a class="link" href="{{ route('drive.index') }}">Google Drive import</a></li>
            </ul>
            <p class="help">Each workspace has its own storage limit — the sidebar shows how much is left. Old files are removed automatically once every enabled account has posted them.</p>
        </div>

        <div class="card card-body">
            <h2 class="card-title">2 · Processing</h2>
            <p class="help">
                The server reads the duration, resolution and audio of each file and makes a thumbnail. A video that is not a vertical
                short, or that no platform will accept, is marked <x-badge value="failed" /> with the reason on the video page, and nothing
                is published. Everything else becomes <x-badge value="ready" />.
            </p>
        </div>

        <div class="card card-body">
            <h2 class="card-title">3 · Captions</h2>
            <p class="help">
                Frames are sampled across the video and sent to the AI model with your audience, tone and language settings. It returns a
                caption and hashtags for Facebook and Instagram, and a title, description and tags for YouTube.
            </p>
            <ul class="mt-2 space-y-2 text-sm text-slate-600">
                <li>If the model is unsure it returns a <strong class="text-slate-900">needs review</strong> result: nothing is published until you look at it.</li>
                <li>If it fails entirely the video stays usable — write the captions yourself and publish as normal.</li>
                <li><strong class="text-slate-900">Generate again</strong> on the video page runs it once more; old versions are kept.</li>
            </ul>
            <p class="help">Set the language, tone, audience and brand context in <a class="link" href="{{ route('settings.ai') }}">AI settings</a>.</p>
        </div>

        <div class="card card-body">
            <h2 class="card-title">4 · Review and approve</h2>
            <p class="help">
                Open a video to edit any caption. Edits are yours and are never overwritten by a later generation. If
                <strong class="text-slate-900">require approval</strong> is on, a post is only scheduled once you approve its caption —
                leave it off and anything valid goes out on its own.
            </p>
        </div>

        <div class="card card-body">
            <h2 class="card-title">5 · Rules and scheduling</h2>
            <p class="help">
                A posting rule per connected account decides when posts go out: a daily limit, the hours of the day, the minimum gap
                between posts, the exact slot times, and which weekdays. ReelFlow fills the next free slot for each account, so the
                same video can go to Facebook, Instagram and YouTube at different times. The
                <a class="link" href="{{ route('calendar') }}">calendar</a> shows what is queued.
            </p>
            <p class="help">Nothing is published while <strong class="text-slate-900">publishing is paused</strong>, and a video is never posted twice to the same account.</p>
        </div>

        <div class="card card-body">
            <h2 class="card-title">6 · Publishing and after</h2>
            <p class="help">
                Posts go out through the official Meta and YouTube APIs. A post that fails for a temporary reason is retried with
                growing gaps; one that fails for good lands on <a class="link" href="{{ route('posts.failed') }}">Failed posts</a> with
                the platform's own message, and you get an email. Views and engagement are collected every few hours where the platform
                allows and shown in <a class="link" href="{{ route('analytics') }}">Analytics</a>.
            </p>
        </div>
    </div>

    <div class="card mt-6">
        <div class="card-header"><h2 class="card-title">When something looks wrong</h2></div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>What you see</th><th>What it means</th><th>What to do</th></tr></thead>
                <tbody>
                    <tr>
                        <td>Video stuck on <x-badge value="processing" /></td>
                        <td>The worker has not picked it up yet, or FFmpeg could not read the file.</td>
                        <td>Wait a few minutes, then check the <a class="link" href="{{ route('logs.index') }}">activity log</a>.</td>
                    </tr>
                    <tr>
                        <td>Captions say <x-badge color="orange">needs review</x-badge></td>
                        <td>The model was not confident about what the video shows.</td>
                        <td>Open the video, fix the caption, approve it.</td>
                    </tr>
                    <tr>
                        <td>Captions failed</td>
                        <td>Usually a free-tier rate limit, or the model returned something unusable.</td>
                        <td>Use <strong>Generate again</strong> later, or write the captions yourself — publishing does not need AI.</td>
                    </tr>
                    <tr>
                        <td>Account needs reconnecting</td>
                        <td>The platform's token expired or permissions changed.</td>
                        <td>Reconnect it on <a class="link" href="{{ route('accounts.index') }}">Connected accounts</a>; queued posts resume.</td>
                    </tr>
                    <tr>
                        <td>Nothing is being published</td>
                        <td>Publishing is paused, the daily limit is reached, or captions are waiting for approval.</td>
                        <td>Check the dashboard banner and your <a class="link" href="{{ route('rules.index') }}">posting rules</a>.</td>
                    </tr>
                    <tr>
                        <td>Out of storage</td>
                        <td>The workspace hit its limit; new uploads are refused.</td>
                        <td>Delete videos you no longer need, or ask the administrator to raise the limit.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    @if(auth()->user()->isAdmin())
        <div class="card mt-6 card-body">
            <h2 class="card-title">For administrators</h2>
            <p class="help">
                <a class="link" href="{{ route('admin.overview') }}">Overview</a> shows caption and publishing health across every
                workspace, why generations failed and what each model actually returned.
                <a class="link" href="{{ route('admin.users.index') }}">Users</a> invites people — each gets their own workspace — and sets
                storage, account and daily AI limits. <a class="link" href="{{ route('logs.failed-jobs') }}">Failed jobs</a> lists
                background work that gave up, and can retry it.
            </p>
        </div>
    @endif
</x-layout>
