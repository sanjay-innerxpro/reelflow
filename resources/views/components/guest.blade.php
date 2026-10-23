@props(['title'])
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex h-full items-center justify-center px-4 text-slate-800">
    <div class="card card-body w-full max-w-sm space-y-4">
        <div class="flex items-center gap-2">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 font-bold text-white">RF</span>
            <h1 class="text-lg font-semibold">{{ $title }}</h1>
        </div>
        @if(session('status'))
            <div class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif
        {{ $slot }}
    </div>
</body>
</html>
