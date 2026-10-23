<x-layout title="Credentials">
    <x-slot:header>
        <div class="max-w-3xl">
            <h1 class="text-xl font-semibold">Credentials</h1>
            <p class="mt-1 text-sm text-slate-500">App IDs, secrets and API keys for every platform. Saved values are encrypted with the app key and take precedence over the server's <code>.env</code> file. Leave a field empty to use <code>.env</code>.</p>
        </div>
        <form method="POST" action="{{ route('admin.credentials.import') }}">
            @csrf
            <button class="btn" title="Copies every credential the server's .env sets into this page. Fields already saved here are kept.">Import from .env</button>
        </form>
    </x-slot:header>

    @unless($credentials->isLoaded())
        <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Saved credentials could not be read, so the values from <code>.env</code> are in effect. Run <code>php artisan migrate</code> if this is a new deploy.
        </div>
    @endunless

    {{-- The test buttons sit inside the main form but submit these. --}}
    @foreach($groups as $id => $group)
        @if($group['test'] ?? false)
            <form id="test-{{ $id }}" method="POST" action="{{ route('admin.credentials.test', $id) }}" class="hidden">@csrf</form>
        @endif
    @endforeach

    <form method="POST" action="{{ route('admin.credentials.update') }}" class="space-y-6" autocomplete="off">
        @csrf @method('PUT')

        @foreach($groups as $id => $group)
            @php
                $fields = collect($group['fields']);
                $advanced = $fields->filter(fn ($f) => $f['advanced'] ?? false);
            @endphp
            <section id="{{ $id }}" class="card card-body scroll-mt-6">
                <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                    <div class="max-w-3xl">
                        <h2 class="card-title">{{ $group['title'] }}</h2>
                        <p class="help">
                            {{ $group['help'] }}
                            @isset($group['link'])<a href="{{ $group['link'][0] }}" target="_blank" rel="noopener noreferrer" class="link">{{ $group['link'][1] }} ↗</a>@endisset
                        </p>
                    </div>
                    @if($group['test'] ?? false)
                        <button form="test-{{ $id }}" class="btn btn-sm" title="Checks the saved values. Save first if you changed something.">Test saved values</button>
                    @endif
                </div>

                @if(session('credential_test.group') === $id)
                    <div @class([
                        'mb-5 rounded-lg border px-4 py-3 text-sm break-words',
                        'border-emerald-200 bg-emerald-50 text-emerald-800' => session('credential_test.ok'),
                        'border-red-200 bg-red-50 text-red-800' => ! session('credential_test.ok'),
                    ])>{{ session('credential_test.message') }}</div>
                @endif

                <div class="grid gap-5 md:grid-cols-2">
                    @foreach($fields->diffKeys($advanced) as $key => $field)
                        @include('admin.credential-field')
                    @endforeach
                </div>

                @if($advanced->isNotEmpty())
                    <details class="mt-5 rounded-lg border border-slate-200 p-4" @if($advanced->keys()->contains(fn ($k) => $credentials->stored($k) !== null)) open @endif>
                        <summary class="cursor-pointer text-sm font-medium">More options</summary>
                        <div class="mt-4 grid gap-5 md:grid-cols-2">
                            @foreach($advanced as $key => $field)
                                @include('admin.credential-field')
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @endforeach

        <div class="sticky bottom-0 z-10 -mx-1 flex items-center justify-end gap-3 rounded-xl border border-slate-200 bg-white/90 px-4 py-3 backdrop-blur">
            <span class="text-xs text-slate-500">Empty secret fields keep their saved value.</span>
            <button class="btn btn-primary">Save credentials</button>
        </div>
    </form>
</x-layout>
