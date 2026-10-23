<x-layout title="Google Drive import">
    <x-slot:header><h1 class="text-xl font-semibold">Google Drive import</h1></x-slot:header>

    <form method="POST" action="{{ route('drive.store') }}" class="card card-body mb-6 flex flex-wrap items-end gap-3">
        @csrf
        <div class="min-w-72 flex-1"><label class="label">Folder link</label><input name="link" required maxlength="500" class="input w-full" value="{{ old('link') }}" placeholder="https://drive.google.com/drive/folders/…"></div>
        <div><label class="label">Library folder name</label><input name="name" required maxlength="120" class="input" value="{{ old('name', 'Google Drive') }}"></div>
        <button class="btn btn-primary">Add folder</button>
        @error('link')<p class="w-full text-sm text-red-600">{{ $message }}</p>@enderror
        <p class="help w-full">
            The server downloads the folder's videos itself every 5 minutes (up to {{ $batch }} per run), so no computer needs to be on.
            In Google Drive, share the folder as <strong>Anyone with the link → Viewer</strong>. New videos added to the folder later are imported automatically;
            a video already in the library (same file) is never imported twice.
            @unless($apiKey) Listing uses Drive's public folder page; an administrator can add a Drive API key in Admin → Credentials to use the official Drive API instead. @endunless
        </p>
    </form>

    <div class="card overflow-x-auto">
        <table class="table">
            <thead><tr><th>Folder</th><th>Status</th><th>Imported</th><th>Waiting</th><th>Duplicates</th><th>Failed / skipped</th><th>Last checked</th><th></th></tr></thead>
            <tbody>
            @forelse($folders as $folder)
                @php($c = $counts->get($folder->id, collect()))
                <tr>
                    <td class="font-medium">{{ $folder->name }}<div><a href="{{ $folder->url() }}" target="_blank" rel="noopener" class="font-mono text-xs text-slate-400 hover:underline">{{ \Illuminate\Support\Str::limit($folder->folder_id, 18) }}</a></div></td>
                    <td><x-badge :value="$folder->enabled ? 'active' : 'paused'" /></td>
                    <td>{{ $c->get('imported', 0) }}</td>
                    <td>{{ $c->get('pending', 0) }}</td>
                    <td>{{ $c->get('duplicate', 0) }}</td>
                    <td>{{ $c->get('failed', 0) + $c->get('skipped', 0) }}</td>
                    <td class="text-xs"><x-time :at="$folder->last_listed_at" />@if($folder->last_error)<div class="max-w-xs text-red-600">{{ \Illuminate\Support\Str::limit($folder->last_error, 160) }}</div>@endif</td>
                    <td class="text-right whitespace-nowrap">
                        <form method="POST" action="{{ route('drive.refresh', $folder) }}" class="inline">@csrf<button class="btn btn-sm">Check now</button></form>
                        <form method="POST" action="{{ route('drive.toggle', $folder) }}" class="inline">@csrf<button class="btn btn-sm">{{ $folder->enabled ? 'Pause' : 'Resume' }}</button></form>
                        <form method="POST" action="{{ route('drive.destroy', $folder) }}" class="inline" data-confirm="Stop importing from {{ $folder->name }}? Videos already imported stay in the library.">@csrf @method('DELETE')<button class="btn btn-sm">Remove</button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="py-8 text-center text-slate-500">No Drive folders yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if($failed->isNotEmpty())
        <h2 class="mt-8 mb-3 font-semibold">Not imported</h2>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>File</th><th>Folder</th><th>Status</th><th>Reason</th></tr></thead>
                <tbody>
                @foreach($failed as $file)
                    <tr>
                        <td class="font-medium">{{ $file->name }}</td>
                        <td>{{ $file->folder->name }}</td>
                        <td><x-badge :value="$file->status" /></td>
                        <td class="max-w-md text-xs text-slate-600">{{ \Illuminate\Support\Str::limit($file->error, 200) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="help mt-3">Failed downloads are retried {{ config('reelflow.drive.max_attempts') }} times; <em>Check now</em> retries them again.</p>
    @endif
</x-layout>
