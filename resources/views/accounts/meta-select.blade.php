<x-layout title="Choose Pages">
    <x-slot:header><h1 class="text-xl font-semibold">Choose what to connect</h1></x-slot:header>

    <form method="POST" action="{{ route('accounts.meta.store', $key) }}" class="space-y-4">
        @csrf
        @php($missing = array_diff(['pages_manage_posts', 'instagram_content_publish'], $scopes))
        @if($missing)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800">Not granted: {{ implode(', ', $missing) }}. Publishing to the affected platform will fail until you reconnect and allow it.</div>
        @endif
        <div class="card overflow-x-auto">
            <table class="table">
                <thead><tr><th>Facebook Page</th><th>Linked Instagram professional account</th></tr></thead>
                <tbody>
                @foreach($pages as $page)
                    <tr>
                        <td>
                            <label class="flex items-center gap-2"><input type="checkbox" name="pages[]" value="{{ $page['id'] }}" checked>
                                <span><span class="font-medium">{{ $page['name'] }}</span> <span class="text-xs text-slate-500">{{ $page['category'] }}</span></span></label>
                            @unless($page['can_publish'])<div class="text-xs text-red-600">Your role on this Page cannot create content.</div>@endunless
                        </td>
                        <td>
                            @if($page['instagram'])
                                <label class="flex items-center gap-2"><input type="checkbox" name="instagram[]" value="{{ $page['instagram']['id'] }}" checked>
                                    {{ '@'.($page['instagram']['username'] ?? $page['instagram']['id']) }}</label>
                            @else
                                <span class="text-xs text-slate-500">None linked (Instagram must be a Business/Creator account connected to this Page).</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <button class="btn btn-primary">Connect selected</button>
        <a href="{{ route('accounts.index') }}" class="btn">Cancel</a>
    </form>
</x-layout>
