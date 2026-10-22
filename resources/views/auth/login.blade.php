<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="flex h-full items-center justify-center px-4 text-slate-800">
    <form method="POST" action="{{ route('login') }}" class="card card-body w-full max-w-sm space-y-4">
        @csrf
        <div class="flex items-center gap-2">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 font-bold text-white">RF</span>
            <h1 class="text-lg font-semibold">{{ config('app.name') }}</h1>
        </div>
        @if($errors->any())
            <div class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="input">
        </div>
        <div>
            <label class="label" for="password">Password</label>
            <input id="password" name="password" type="password" required autocomplete="current-password" class="input">
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button class="btn btn-primary w-full">Sign in</button>
    </form>
</body>
</html>
