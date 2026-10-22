<x-layout title="Sync devices">
    <x-slot:header><h1 class="text-xl font-semibold">Sync devices</h1></x-slot:header>

    @if(session('new_token'))
        <div class="card card-body mb-6 border-emerald-300 bg-emerald-50 text-sm">
            <h2 class="font-semibold text-emerald-900">Device "{{ session('new_device') }}" created</h2>
            <p class="mt-1 text-emerald-800">Copy this credential into the agent's <code>.env</code> now. It is stored only as a hash and will not be shown again.</p>
            <div class="mt-3 flex items-center gap-2">
                <code id="new-token" class="block flex-1 rounded bg-white p-2 font-mono text-xs break-all">{{ session('new_token') }}</code>
                <button type="button" class="btn btn-sm" data-copy="#new-token">Copy</button>
            </div>
            <pre class="mt-3 rounded bg-white p-2 text-xs">REELFLOW_SERVER_URL={{ $apiUrl }}
REELFLOW_DEVICE_TOKEN=&lt;the token above&gt;</pre>
        </div>
    @endif

    <form method="POST" action="{{ route('devices.store') }}" class="card card-body mb-6 flex flex-wrap items-end gap-3">
        @csrf
        <div><label class="label">Device name</label><input name="name" required maxlength="100" class="input" placeholder="Home PC"></div>
        <button class="btn btn-primary">Register device</button>
        <p class="help w-full">The agent can only register and upload videos; it cannot read accounts or settings. Revoke a device at any time.</p>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Name</th><th>Status</th><th>Last seen</th><th>Agent</th><th>Videos</th><th>Last error</th><th></th></tr></thead>
            <tbody>
            @forelse($devices as $device)
                <tr>
                    <td class="font-medium">{{ $device->name }}<div class="font-mono text-xs text-slate-400">…{{ $device->token_hint }}</div></td>
                    <td>
                        @if($device->isActive())<x-badge :value="$device->isOnline() ? 'online' : 'offline'" />@else<x-badge value="revoked" />@endif
                    </td>
                    <td class="text-xs"><x-time :at="$device->last_seen_at" /><div class="text-slate-400">{{ $device->last_ip }}</div></td>
                    <td class="text-xs">{{ $device->agent_version ?? '—' }}<div class="text-slate-400">{{ $device->platform_info }}</div></td>
                    <td>{{ $device->videos_count }}</td>
                    <td class="max-w-xs text-xs text-red-600">{{ \Illuminate\Support\Str::limit($device->last_error, 120) }}</td>
                    <td class="text-right">
                        @if($device->isActive())
                            <form method="POST" action="{{ route('devices.revoke', $device) }}" data-confirm="Revoke {{ $device->name }}? The agent will stop working immediately.">@csrf<button class="btn btn-sm">Revoke</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-500">No devices yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="help mt-3">A device is shown offline after {{ $offlineAfter }} minutes without contact; an email alert is sent once. Already-uploaded videos keep publishing while devices are offline.</p>
</x-layout>
