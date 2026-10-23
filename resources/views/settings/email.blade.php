<x-layout title="Email settings">
    <x-slot:header><h1 class="text-xl font-semibold">Email notifications</h1></x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('settings.email.update') }}" class="card card-body space-y-4 lg:col-span-2">
            @csrf @method('PUT')
            <div>
                <label class="label">Recipients (comma or newline separated)</label>
                <textarea name="recipients" rows="2" class="input">{{ old('recipients', implode(', ', (array) $settings['notifications.recipients'])) }}</textarea>
            </div>
            <fieldset class="space-y-2 text-sm">
                <legend class="label">Send an email when…</legend>
                @foreach([
                    'on_success' => 'A post is published (includes the post URL)',
                    'on_failure' => 'A post fails permanently',
                    'on_auth_problem' => 'An account token expires or permissions are revoked',
                    'on_device_offline' => 'A sync agent goes offline',
                    'on_upload_failures' => 'A video fails to upload repeatedly',
                    'daily_summary' => 'Daily summary',
                ] as $key => $label)
                    <label class="flex items-center gap-2"><input type="checkbox" name="{{ $key }}" value="1" @checked($settings['notifications.'.$key])> {{ $label }}</label>
                @endforeach
            </fieldset>
            <div class="w-40">
                <label class="label">Daily summary time ({{ \App\Support\DisplayTime::timezone() }})</label>
                <input name="daily_summary_time" value="{{ old('daily_summary_time', $settings['notifications.daily_summary_time']) }}" class="input">
            </div>
            <button class="btn btn-primary">Save</button>
        </form>

        <div class="space-y-6">
            @if(auth()->user()->isAdmin())
            <div class="card card-body text-sm">
                <h2 class="card-title mb-2">Mail transport (from .env)</h2>
                <dl class="grid grid-cols-2 gap-y-1">
                    <dt class="text-slate-500">Mailer</dt><dd>{{ $mail['mailer'] }}</dd>
                    <dt class="text-slate-500">Host</dt><dd>{{ $mail['host'] ?? '—' }}:{{ $mail['port'] ?? '' }}</dd>
                    <dt class="text-slate-500">Encryption</dt><dd>{{ $mail['scheme'] ?? 'auto' }}</dd>
                    <dt class="text-slate-500">Username</dt><dd>{{ $mail['username_set'] ? 'set' : 'not set' }}</dd>
                    <dt class="text-slate-500">Password</dt><dd>{{ $mail['password_set'] ? 'set' : 'not set' }}</dd>
                    <dt class="text-slate-500">From</dt><dd class="truncate">{{ $mail['from'] }}</dd>
                </dl>
                @if($mail['mailer'] === 'log')<p class="help text-amber-700">MAIL_MAILER=log writes emails to the log file instead of sending them.</p>@endif
                <form method="POST" action="{{ route('settings.email.validate') }}" class="mt-3">@csrf<button class="btn btn-sm">Validate SMTP connection</button></form>
            </div>
            @endif
            <form method="POST" action="{{ route('settings.email.test') }}" class="card card-body space-y-2 text-sm">
                @csrf
                <h2 class="card-title">Send a test email</h2>
                <input name="email" type="email" required value="{{ auth()->user()->email }}" class="input">
                <button class="btn btn-sm">Send test</button>
            </form>
        </div>
    </div>

    <div class="card mt-6 overflow-x-auto">
        <div class="border-b border-slate-100 px-5 py-3"><h2 class="card-title">Recent notifications</h2></div>
        <table class="table">
            <thead><tr><th>When</th><th>Type</th><th>Subject</th><th>To</th><th>Status</th></tr></thead>
            <tbody>
            @forelse($logs as $log)
                <tr>
                    <td class="whitespace-nowrap text-xs"><x-time :at="$log->created_at" /></td>
                    <td class="text-xs">{{ $log->type }}</td>
                    <td>{{ $log->subject }}</td>
                    <td class="text-xs">{{ $log->recipient }}</td>
                    <td><x-badge :value="$log->status" />@if($log->error)<div class="text-xs text-red-600">{{ \Illuminate\Support\Str::limit($log->error, 100) }}</div>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-slate-500">No notifications sent yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
