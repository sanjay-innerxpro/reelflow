@props(['title' => null])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <x-theme-script />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full">
@php
    $user = auth()->user();
    $nav = [
        'Create' => [
            ['Dashboard', 'dashboard', 'dashboard', 'home'],
            ['Upload videos', 'uploads.index', 'uploads.*', 'upload'],
            ['Video library', 'videos.index', 'videos.*', 'video'],
            ['Calendar', 'calendar', 'calendar', 'calendar'],
        ],
        'Publishing' => [
            ['History', 'posts.index', 'posts.index', 'clock'],
            ['Failed posts', 'posts.failed', 'posts.failed', 'warning'],
            ['Posting rules', 'rules.index', 'rules.*', 'adjustments'],
            ['Analytics', 'analytics', 'analytics', 'chart'],
        ],
        'Setup' => [
            ['Connected accounts', 'accounts.index', 'accounts.*', 'link'],
            ['Google Drive import', 'drive.index', 'drive.*', 'cloud'],
            ['Sync devices', 'devices.index', 'devices.*', 'computer'],
            ['AI settings', 'settings.ai', 'settings.ai', 'sparkles'],
            ['Email settings', 'settings.email', 'settings.email', 'envelope'],
            ['Activity log', 'logs.index', 'logs.index', 'list'],
            ['User guide', 'guide', 'guide', 'book'],
        ],
    ];
    if ($user->isAdmin()) {
        $nav['Admin'] = [
            ['Overview', 'admin.overview', 'admin.overview', 'chart'],
            ['Users', 'admin.users.index', 'admin.users.*', 'users'],
            ['Credentials', 'admin.credentials', 'admin.credentials*', 'key'],
            ['Failed jobs', 'logs.failed-jobs', 'logs.failed-jobs', 'alert'],
        ];
    }
    $failedCount = \App\Models\ScheduledPost::where('status', 'failed')->count();
    $workspace = \App\Support\Tenancy\Tenant::workspace();
    $storageUsed = $workspace ? \Illuminate\Support\Facades\Cache::remember('reelflow:storage-used:'.$workspace->id, 60, fn () => $workspace->storageUsedBytes()) : 0;
    $storageLimit = $workspace?->storageLimitBytes();
    $initials = collect(preg_split('/\s+/', trim($user->name)))->take(2)->map(fn ($p) => mb_substr($p, 0, 1))->implode('');
@endphp

<div class="min-h-full">
    {{-- Mobile top bar --}}
    <div class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur lg:hidden">
        <button type="button" class="btn btn-ghost -ml-2 h-9 w-9 p-0" data-sidebar-open aria-label="Open menu"><x-icon name="menu" /></button>
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2 font-semibold">
            <span class="brand-gradient flex h-7 w-7 items-center justify-center rounded-lg text-on-color"><x-icon name="play" class="h-4 w-4" /></span>
            {{ config('app.name') }}
        </a>
    </div>

    <div data-sidebar-backdrop class="fixed inset-0 z-40 hidden bg-slate-950/40 backdrop-blur-sm lg:hidden"></div>

    <aside data-sidebar class="fixed inset-y-0 left-0 z-50 flex w-72 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 lg:z-20 lg:w-64 lg:translate-x-0">
        <div class="flex h-16 shrink-0 items-center justify-between px-5">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5">
                <span class="brand-gradient flex h-9 w-9 items-center justify-center rounded-xl text-on-color shadow-(--shadow-lift)"><x-icon name="play" class="h-5 w-5" /></span>
                <span class="text-[15px] font-semibold tracking-tight text-slate-900">{{ config('app.name') }}</span>
            </a>
            <button type="button" class="btn btn-ghost h-8 w-8 p-0 lg:hidden" data-sidebar-close aria-label="Close menu"><x-icon name="x" /></button>
        </div>

        @if($workspace)
            <div class="mx-3 mb-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
                <div class="truncate text-xs font-semibold text-slate-900">{{ $workspace->name }}</div>
                <div class="mt-1.5 flex items-center justify-between text-[11px] text-slate-500">
                    <span>Storage</span>
                    <span>{{ \Illuminate\Support\Number::fileSize($storageUsed, 1) }} / {{ $storageLimit ? \Illuminate\Support\Number::fileSize($storageLimit) : 'unlimited' }}</span>
                </div>
                @if($storageLimit)
                    @php($pct = min(100, round($storageUsed / $storageLimit * 100)))
                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-200"><div class="h-full rounded-full {{ $pct > 90 ? 'bg-red-500' : 'brand-gradient' }}" style="width: {{ $pct }}%"></div></div>
                @endif
            </div>
        @endif

        <nav class="flex-1 space-y-4 overflow-y-auto px-3 py-1">
            @foreach($nav as $section => $items)
                <div>
                    <div class="px-2.5 pb-1 text-[11px] font-semibold tracking-wider text-slate-400 uppercase">{{ $section }}</div>
                    <ul class="space-y-0.5">
                        @foreach($items as [$label, $route, $pattern, $icon])
                            <li>
                                <a href="{{ route($route) }}" class="nav-link" @if(request()->routeIs($pattern)) aria-current="page" @endif>
                                    <x-icon :name="$icon" />
                                    <span class="flex-1 truncate">{{ $label }}</span>
                                    @if($route === 'posts.failed' && $failedCount)
                                        <span class="rounded-full bg-red-100 px-1.5 py-0.5 text-[11px] font-semibold text-red-700">{{ $failedCount }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
            @if(config('reelflow.queue_mode') !== 'cron' && $user->isAdmin())
                <a href="{{ url(config('horizon.path', 'horizon')) }}" class="nav-link"><x-icon name="bolt" /> Queue monitor ↗</a>
            @endif
        </nav>

        <div class="shrink-0 border-t border-slate-200 p-3">
            <div class="flex items-center gap-3 rounded-xl px-2 py-2">
                <span class="brand-gradient flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-on-color">{{ $initials }}</span>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-medium text-slate-900">{{ $user->name }}</div>
                    <div class="truncate text-xs text-slate-500">{{ $user->email }}</div>
                </div>
                <button type="button" class="btn btn-ghost h-8 w-8 p-0" data-theme-toggle aria-label="Switch light/dark theme" title="Light / dark">
                    <x-icon name="moon" class="h-[18px] w-[18px] dark:hidden" /><x-icon name="sun" class="hidden h-[18px] w-[18px] dark:block" />
                </button>
            </div>
            <div class="mt-1 grid grid-cols-2 gap-1">
                <a href="{{ route('account') }}" class="nav-link justify-center text-xs"><x-icon name="user" class="h-4 w-4" /> Account</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="nav-link w-full justify-center text-xs"><x-icon name="logout" class="h-4 w-4" /> Sign out</button>
                </form>
            </div>
        </div>
    </aside>

    <main class="lg:pl-64">
        {{-- Full width: a 1440px cap left wide screens with empty gutters on
             both sides, and this app's pages are grids and tables that use
             every pixel. --}}
        <div class="w-full px-4 py-5 sm:px-5 lg:px-6 lg:py-6">
            @isset($header)
                <div class="page-header mb-6 flex flex-wrap items-center justify-between gap-3">{{ $header }}</div>
            @endisset

            @if(session('status'))
                <div class="mb-5 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <x-icon name="check-circle" class="mt-px h-5 w-5 shrink-0 text-emerald-600" />
                    <span>{{ session('status') }}</span>
                </div>
            @endif
            @if($errors->any())
                <div class="mb-5 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <x-icon name="alert" class="mt-px h-5 w-5 shrink-0 text-red-600" />
                    <ul class="space-y-0.5">
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
