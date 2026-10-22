@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full text-slate-800 antialiased">
@php
    $nav = [
        'Overview' => [
            ['Dashboard', 'dashboard', 'dashboard'],
            ['Video library', 'videos.index', 'videos.*'],
            ['Calendar', 'calendar', 'calendar'],
        ],
        'Publishing' => [
            ['History', 'posts.index', 'posts.index'],
            ['Failed posts', 'posts.failed', 'posts.failed'],
            ['Posting rules', 'rules.index', 'rules.*'],
            ['Analytics', 'analytics', 'analytics'],
        ],
        'Setup' => [
            ['Connected accounts', 'accounts.index', 'accounts.*'],
            ['Sync devices', 'devices.index', 'devices.*'],
            ['AI settings', 'settings.ai', 'settings.ai'],
            ['Email settings', 'settings.email', 'settings.email'],
            ['System logs', 'logs.index', 'logs.*'],
        ],
    ];
    $failedCount = \App\Models\ScheduledPost::where('status', 'failed')->count();
@endphp
<div class="min-h-full lg:flex">
    <aside class="border-b border-slate-200 bg-white lg:fixed lg:inset-y-0 lg:w-60 lg:border-r lg:border-b-0">
        <div class="flex h-14 items-center gap-2 px-5">
            <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-sm font-bold text-white">RF</span>
            <span class="font-semibold">{{ config('app.name') }}</span>
        </div>
        <nav class="flex gap-4 overflow-x-auto px-3 pb-3 lg:block lg:space-y-5 lg:overflow-visible lg:pb-0">
            @foreach($nav as $section => $items)
                <div class="shrink-0">
                    <div class="hidden px-2 pb-1 text-[11px] font-semibold tracking-wider text-slate-400 uppercase lg:block">{{ $section }}</div>
                    <ul class="flex gap-1 lg:block lg:space-y-0.5">
                        @foreach($items as [$label, $route, $pattern])
                            <li>
                                <a href="{{ route($route) }}" class="flex items-center justify-between rounded-md px-2 py-1.5 text-sm whitespace-nowrap {{ request()->routeIs($pattern) ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-600 hover:bg-slate-100' }}">
                                    {{ $label }}
                                    @if($route === 'posts.failed' && $failedCount)
                                        <span class="ml-2 rounded-full bg-red-100 px-1.5 text-xs font-medium text-red-700">{{ $failedCount }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            <div class="hidden border-t border-slate-200 pt-3 lg:block">
                @if(config('reelflow.queue_mode') !== 'cron')
                    <a href="{{ url(config('horizon.path', 'horizon')) }}" class="block rounded-md px-2 py-1.5 text-sm text-slate-600 hover:bg-slate-100">Queue monitor ↗</a>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="px-2 py-1.5">
                    @csrf
                    <button class="text-sm text-slate-500 hover:text-slate-800">Sign out ({{ auth()->user()->name }})</button>
                </form>
            </div>
        </nav>
    </aside>

    <main class="flex-1 lg:pl-60">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
            @isset($header)
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3">{{ $header }}</div>
            @endisset

            @if(session('status'))
                <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <ul class="list-disc space-y-0.5 pl-5">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </div>
    </main>
</div>
</body>
</html>
