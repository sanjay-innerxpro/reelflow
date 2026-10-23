<x-layout title="Upload videos">
    <x-slot:header>
        <h1 class="text-xl font-semibold">Upload videos</h1>
        <a href="{{ route('videos.index') }}" class="btn">Video library</a>
    </x-slot:header>

    <div data-uploader
         data-store-url="{{ route('uploads.store') }}"
         data-max-bytes="{{ $limits['max_bytes'] }}"
         data-max-videos="{{ $limits['max_videos'] }}"
         data-extensions="{{ implode(',', $limits['extensions']) }}">
        <label data-dropzone class="card flex cursor-pointer flex-col items-center justify-center gap-2 border-2 border-dashed border-slate-300 px-6 py-12 text-center transition hover:border-indigo-400 hover:bg-indigo-50/40">
            <svg class="h-10 w-10 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/></svg>
            <span class="text-base font-medium">Drop a ZIP or videos here, or click to choose</span>
            <span class="text-sm text-slate-500">Up to {{ $limits['max_videos'] }} videos and {{ $limits['max_mb'] }} MB per upload · {{ strtoupper(implode(', ', $limits['extensions'])) }} or ZIP</span>
            <input type="file" class="sr-only" multiple accept=".zip,{{ collect($limits['extensions'])->map(fn ($e) => '.'.$e)->implode(',') }}">
        </label>
        <p data-upload-error class="mt-3 hidden rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700"></p>
        <ul data-upload-list class="mt-4 space-y-3"></ul>
    </div>

    <p class="help mt-4">
        Captions are written automatically after upload. Uploads keep going if the connection drops briefly; keep this page open until each one shows its result.
        Videos already in your library are recognised and not added twice.
    </p>

    @if($batches->isNotEmpty())
        <h2 class="mt-8 mb-3 font-semibold">Recent uploads</h2>
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>File</th><th>Status</th><th>Added</th><th>Details</th><th>When</th></tr></thead>
                <tbody>
                @foreach($batches as $batch)
                    @php($rows = collect($batch->results ?? []))
                    <tr>
                        <td class="font-medium">{{ $batch->original_name }} <span class="text-xs text-slate-400">{{ \Illuminate\Support\Number::fileSize($batch->size_bytes, 1) }}</span></td>
                        <td><x-badge :value="$batch->status" :color="$batch->status === 'completed' ? 'green' : ($batch->status === 'failed' ? 'red' : null)" /></td>
                        <td>{{ $batch->added_count }}</td>
                        <td class="max-w-md text-xs text-slate-600">
                            @if($batch->error)
                                <span class="text-red-600">{{ $batch->error }}</span>
                            @else
                                {{ $rows->countBy('status')->map(fn ($n, $s) => "{$n} {$s}")->implode(' · ') }}
                                @foreach($rows->whereNotIn('status', ['added']) as $row)
                                    <div>{{ $row['name'] }}: {{ $row['message'] }}</div>
                                @endforeach
                            @endif
                        </td>
                        <td class="text-xs"><x-time :at="$batch->created_at" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-layout>
