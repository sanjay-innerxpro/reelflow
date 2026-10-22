<!DOCTYPE html>
<html lang="en" class="bg-white">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} · {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
</head>
<body class="text-slate-800">
<main class="mx-auto max-w-3xl space-y-4 px-4 py-10 text-sm leading-6">
    <h1 class="text-2xl font-semibold">{{ $title }}</h1>
    <p class="text-slate-500">{{ config('app.name') }} · Operated by {{ config('reelflow.operator_name') }} · Contact: {{ config('reelflow.admin_email') ?: 'see the operator' }}</p>
    {{ $slot }}
    <p class="pt-6 text-xs text-slate-400"><a class="link" href="{{ route('legal.privacy') }}">Privacy</a> · <a class="link" href="{{ route('legal.terms') }}">Terms</a> · <a class="link" href="{{ route('legal.data-deletion') }}">Data deletion</a></p>
</main>
</body>
</html>
