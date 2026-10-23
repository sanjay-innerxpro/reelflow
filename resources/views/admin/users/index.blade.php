<x-layout title="Users">
    <x-slot:header>
        <h1 class="text-xl font-semibold">Users</h1>
        <a href="{{ route('admin.users.create') }}" class="btn btn-primary">Invite user</a>
    </x-slot:header>

    @if(session('invite_url'))
        <div class="card card-body mb-6 border-indigo-200 bg-indigo-50 text-sm">
            <h2 class="font-semibold text-indigo-900">Invitation link for {{ session('invite_email') }}</h2>
            <p class="mt-1 text-indigo-800">Valid for 7 days. The person opens it, chooses a password and signs in to their own workspace.</p>
            <div class="mt-3 flex items-center gap-2">
                <code id="invite-url" class="block flex-1 rounded bg-white p-2 font-mono text-xs break-all">{{ session('invite_url') }}</code>
                <button type="button" class="btn btn-sm" data-copy="#invite-url">Copy</button>
            </div>
        </div>
    @endif

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>User</th><th>Status</th><th>Storage</th><th>Videos</th><th>Accounts</th><th>Posts (7 days)</th><th>AI / day</th><th>Last sign-in</th><th></th></tr></thead>
            <tbody>
            @foreach($users as $user)
                @php
                    $ws = $user->workspace;
                    $used = (int) ($storage[$ws?->id] ?? 0);
                    $limit = $ws?->storageLimitBytes();
                    $status = ! $ws ? 'error' : ($ws->isSuspended() ? 'suspended' : ($user->last_login_at ? 'active' : 'invited'));
                @endphp
                <tr>
                    <td>
                        <div class="font-medium">{{ $user->name }} @if($user->isAdmin())<x-badge color="indigo">admin</x-badge>@endif</div>
                        <div class="text-xs text-slate-500">{{ $user->email }}</div>
                        <div class="text-xs text-slate-400">{{ $ws?->name }}</div>
                    </td>
                    <td><x-badge :value="$status" :color="$status === 'suspended' ? 'red' : ($status === 'invited' ? 'blue' : null)" /></td>
                    <td class="text-xs whitespace-nowrap">
                        {{ \Illuminate\Support\Number::fileSize($used, 1) }} / {{ $limit ? \Illuminate\Support\Number::fileSize($limit) : '∞' }}
                        @if($limit)
                            <div class="mt-1 h-1.5 w-24 rounded bg-slate-100"><div class="h-1.5 rounded {{ $used / $limit > 0.9 ? 'bg-red-500' : 'bg-indigo-500' }}" style="width: {{ min(100, round($used / $limit * 100)) }}%"></div></div>
                        @endif
                    </td>
                    <td>{{ $videos[$ws?->id] ?? 0 }}</td>
                    <td>{{ $accounts[$ws?->id] ?? 0 }} / {{ $ws?->max_accounts ?: '∞' }}</td>
                    <td>{{ $published[$ws?->id] ?? 0 }}</td>
                    <td>{{ $ws?->daily_ai_limit ?: '∞' }}</td>
                    <td class="text-xs"><x-time :at="$user->last_login_at" /></td>
                    <td class="text-right whitespace-nowrap">
                        <a href="{{ route('admin.users.edit', $user) }}" class="btn btn-sm">Edit</a>
                        @unless($user->last_login_at)
                            <form method="POST" action="{{ route('admin.users.invite', $user) }}" class="inline">@csrf<button class="btn btn-sm">New invite link</button></form>
                        @endunless
                        @if($ws && $ws->id !== auth()->user()->workspace_id)
                            <form method="POST" action="{{ route('admin.users.suspend', $user) }}" class="inline" @unless($ws->isSuspended()) data-confirm="Suspend {{ $user->name }}? They cannot sign in and nothing is published for them until you resume." @endunless>@csrf<button class="btn btn-sm">{{ $ws->isSuspended() ? 'Resume' : 'Suspend' }}</button></form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="help mt-3">Each user has a private workspace: their own videos, connected accounts, posting rules and settings. Limits of 0 mean unlimited.</p>
</x-layout>
