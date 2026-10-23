@props(['title'])
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    <x-theme-script />
    @vite(['resources/css/app.css'])
</head>
<body class="h-full">
    <div class="grid min-h-full lg:grid-cols-2">
        <div class="flex items-center justify-center px-4 py-12 sm:px-6">
            <div class="w-full max-w-sm">
                <a href="{{ url('/') }}" class="flex items-center gap-2.5">
                    <span class="brand-gradient flex h-10 w-10 items-center justify-center rounded-xl text-on-color shadow-(--shadow-lift)"><x-icon name="play" class="h-5 w-5" /></span>
                    <span class="text-base font-semibold tracking-tight text-slate-900">{{ config('app.name') }}</span>
                </a>
                <h1 class="mt-8 text-2xl font-semibold tracking-tight text-slate-900">{{ $title }}</h1>

                @if(session('status'))
                    <div class="mt-5 flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-sm text-emerald-800"><x-icon name="check-circle" class="h-5 w-5 shrink-0 text-emerald-600" /> {{ session('status') }}</div>
                @endif
                @if($errors->any())
                    <div class="mt-5 flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 px-3 py-2.5 text-sm text-red-700"><x-icon name="alert" class="h-5 w-5 shrink-0 text-red-600" /> {{ $errors->first() }}</div>
                @endif

                <div class="mt-6">{{ $slot }}</div>
            </div>
        </div>

        <div class="brand-gradient relative hidden overflow-hidden lg:block">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_10%,rgb(255_255_255/0.3),transparent_45%),radial-gradient(circle_at_90%_90%,rgb(0_0_0/0.25),transparent_50%)]"></div>
            <div class="relative flex h-full flex-col justify-center px-16 text-on-color">
                <h2 class="max-w-md text-4xl font-semibold tracking-tight">Your reels, posted on autopilot.</h2>
                <ul class="mt-8 space-y-4 text-base">
                    @foreach(['Upload a ZIP of up to 20 reels at once', 'AI writes captions and hashtags for each one', 'Publishes to Facebook & Instagram on your schedule'] as $point)
                        <li class="flex items-center gap-3"><span class="flex h-7 w-7 items-center justify-center rounded-full bg-white/20"><x-icon name="check" class="h-4 w-4" /></span> {{ $point }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
