<x-layout :title="$user ? 'Edit '.$user->name : 'Invite user'">
    <x-slot:header><h1 class="text-xl font-semibold">{{ $user ? 'Edit '.$user->name : 'Invite a user' }}</h1></x-slot:header>

    <form method="POST" action="{{ $user ? route('admin.users.update', $user) : route('admin.users.store') }}" class="card card-body max-w-2xl space-y-5">
        @csrf
        @if($user) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-2">
            <div><label class="label">Name</label><input name="name" required maxlength="100" class="input w-full" value="{{ old('name', $user?->name) }}"></div>
            <div>
                <label class="label">Email</label>
                @if($user)
                    <input class="input w-full bg-slate-50" value="{{ $user->email }}" disabled>
                @else
                    <input name="email" type="email" required maxlength="190" class="input w-full" value="{{ old('email') }}">
                @endif
            </div>
            <div class="sm:col-span-2"><label class="label">Workspace name</label><input name="workspace_name" maxlength="120" class="input w-full" value="{{ old('workspace_name', $workspace?->name) }}" placeholder="Defaults to “Name's workspace”"></div>
        </div>

        <fieldset class="space-y-3">
            <legend class="font-medium">Limits <span class="text-sm font-normal text-slate-500">(0 = unlimited)</span></legend>
            <div class="grid gap-4 sm:grid-cols-3">
                <div><label class="label">Storage (MB)</label><input name="storage_limit_mb" type="number" min="0" required class="input w-full" value="{{ old('storage_limit_mb', $workspace->storage_limit_mb) }}"></div>
                <div><label class="label">Connected accounts</label><input name="max_accounts" type="number" min="0" required class="input w-full" value="{{ old('max_accounts', $workspace->max_accounts) }}"></div>
                <div><label class="label">AI captions per day</label><input name="daily_ai_limit" type="number" min="0" required class="input w-full" value="{{ old('daily_ai_limit', $workspace->daily_ai_limit) }}"></div>
            </div>
            <p class="help">Storage counts videos waiting or posted in the last {{ config('reelflow.storage.cleanup_published_after_days') ?? '—' }} days (originals are deleted after that). The free AI quota is shared by everyone, so keep per-user AI limits modest.</p>
        </fieldset>

        <div class="flex gap-2">
            <button class="btn btn-primary">{{ $user ? 'Save' : 'Create and get invitation link' }}</button>
            <a href="{{ route('admin.users.index') }}" class="btn">Cancel</a>
        </div>
    </form>
</x-layout>
