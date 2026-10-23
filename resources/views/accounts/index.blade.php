<x-layout title="Connected accounts">
    <x-slot:header>
        <h1 class="text-xl font-semibold">Connected accounts</h1>
        <div class="flex gap-2">
            @if($metaConfigured)
                <a href="{{ route('accounts.meta.connect') }}" class="btn btn-primary">Connect Facebook / Instagram</a>
            @endif
            @if($googleConfigured)
                <a href="{{ route('accounts.google.connect') }}" class="btn btn-primary">Connect YouTube</a>
            @endif
        </div>
    </x-slot:header>

    @if(auth()->user()->isAdmin() && (! $metaConfigured || ($youtubeEnabled && ! $googleConfigured)))
        <div class="card card-body mb-6 text-sm">
            <h2 class="card-title mb-2">Platform credentials needed</h2>
            @unless($metaConfigured)
                <p>• <strong>Meta</strong>: set <code>META_APP_ID</code>, <code>META_APP_SECRET</code> and <code>META_REDIRECT_URI</code> (= <code>{{ route('accounts.meta.callback') }}</code>) in the server <code>.env</code>.</p>
            @endunless
            @if($youtubeEnabled && ! $googleConfigured)
                <p>• <strong>Google</strong>: set <code>GOOGLE_CLIENT_ID</code>, <code>GOOGLE_CLIENT_SECRET</code> and <code>GOOGLE_REDIRECT_URI</code> (= <code>{{ route('accounts.google.callback') }}</code>).</p>
            @endif
            <p class="help">See README → "Meta developer app" and "Google Cloud / YouTube API".</p>
        </div>
    @endif

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Account</th><th>Type</th><th>Status</th><th>Auto-posting</th><th>Last authorised</th><th>Permissions</th><th></th></tr></thead>
            <tbody>
            @forelse($accounts as $account)
                <tr>
                    <td>
                        <div class="flex items-center gap-2"><x-platform :platform="$account->platform" /> <span class="font-medium">{{ $account->displayName() }}</span></div>
                        @if($account->parent)<div class="text-xs text-slate-500">via Page {{ $account->parent->name }}</div>@endif
                    </td>
                    <td class="text-xs">{{ str_replace('_', ' ', $account->account_type) }}</td>
                    <td>
                        <x-badge :value="$account->paused_until?->isFuture() ? 'paused' : $account->status" />
                        @if($account->paused_until?->isFuture())<div class="text-xs text-slate-500">until <x-time :at="$account->paused_until" /></div>@endif
                        @if($account->status_reason)<div class="max-w-xs text-xs text-slate-500">{{ $account->status_reason }}</div>@endif
                    </td>
                    <td>
                        <a href="{{ route('rules.edit', $account) }}" class="link text-sm">{{ $account->postingRule?->enabled ? $account->postingRule->daily_limit.'/day' : 'Off' }}</a>
                    </td>
                    <td class="text-xs"><x-time :at="$account->last_authenticated_at" /></td>
                    <td class="max-w-xs text-xs text-slate-500">{{ implode(', ', array_map(fn ($s) => \Illuminate\Support\Str::afterLast($s, '/'), $account->scopes ?? [])) ?: '—' }}</td>
                    <td class="text-right whitespace-nowrap">
                        @if($account->status->needsReconnect() || $account->status === \App\Enums\AccountStatus::Disconnected)
                            <a href="{{ $account->platform === \App\Enums\Platform::YouTube ? route('accounts.google.connect') : route('accounts.meta.connect') }}" class="btn btn-sm btn-primary">Reconnect</a>
                        @endif
                        @if($account->failed_count)
                            <form method="POST" action="{{ route('accounts.retry-failed', $account) }}" class="inline">@csrf<button class="btn btn-sm">Retry {{ $account->failed_count }} failed</button></form>
                        @endif
                        @if($account->status === \App\Enums\AccountStatus::Connected && ! $account->paused_until?->isFuture())
                            <form method="POST" action="{{ route('accounts.pause', $account) }}" class="inline">@csrf<button class="btn btn-sm">Pause</button></form>
                        @elseif($account->status === \App\Enums\AccountStatus::Paused || $account->paused_until?->isFuture())
                            <form method="POST" action="{{ route('accounts.resume', $account) }}" class="inline">@csrf<button class="btn btn-sm">Resume</button></form>
                        @endif
                        @if($account->status !== \App\Enums\AccountStatus::Disconnected)
                            <form method="POST" action="{{ route('accounts.disconnect', $account) }}" class="inline" data-confirm="Disconnect {{ $account->name }}? Stored tokens will be deleted and auto-posting disabled.">@csrf @method('DELETE')<button class="btn btn-sm">Disconnect</button></form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="py-8 text-center text-slate-500">No accounts connected yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6 grid gap-6 md:grid-cols-2 text-sm">
        <div class="card card-body">
            <h2 class="card-title mb-2">Facebook &amp; Instagram requirements</h2>
            <ul class="list-disc space-y-1 pl-5 text-slate-600">
                <li>Facebook Reels publish to <strong>Pages</strong> where you have a content-creation role.</li>
                <li>Instagram requires a <strong>Professional (Business or Creator)</strong> account linked to a Facebook Page.</li>
                <li>Requested permissions: <span class="font-mono text-xs">{{ implode(', ', $metaScopes) }}</span>. Most need Meta App Review before use with accounts that are not app testers.</li>
                <li>Instagram limits API publishing per 24 hours; ReelFlow checks the quota before each post.</li>
            </ul>
        </div>
        @if($youtubeEnabled)
        <div class="card card-body">
            <h2 class="card-title mb-2">YouTube requirements</h2>
            <ul class="list-disc space-y-1 pl-5 text-slate-600">
                <li>Requested scopes: <span class="font-mono text-xs">{{ implode(', ', array_map(fn ($s) => \Illuminate\Support\Str::afterLast($s, '/'), $googleScopes)) }}</span>.</li>
                <li>Uploads from unverified Google Cloud projects are locked to <strong>private</strong> until the project passes YouTube's API audit.</li>
                <li>Daily API quota is limited (default 10,000 units); each upload uses a large share. Quota errors pause the channel until midnight Pacific time.</li>
                <li>Whether a video appears as a Short is decided by YouTube from its format (vertical/square, length limit).</li>
            </ul>
        </div>
        @endif
    </div>
</x-layout>
