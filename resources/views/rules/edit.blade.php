<x-layout title="Posting rule">
    <x-slot:header>
        <div>
            <a href="{{ route('rules.index') }}" class="link text-sm">← Posting rules</a>
            <h1 class="flex items-center gap-2 text-xl font-semibold"><x-platform :platform="$account->platform" /> {{ $account->name }}</h1>
        </div>
    </x-slot:header>

    <form method="POST" action="{{ route('rules.update', $account) }}" class="card card-body grid gap-4 md:grid-cols-2">
        @csrf @method('PUT')
        <label class="flex items-center gap-2 text-sm md:col-span-2"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $rule->enabled))> <strong>Enable automatic scheduling and publishing</strong> (no manual approval unless approval mode is on)</label>

        <div><label class="label">Timezone</label>
            <select name="timezone" class="input">
                @foreach(timezone_identifiers_list() as $tz)<option value="{{ $tz }}" @selected(old('timezone', $rule->timezone) === $tz)>{{ $tz }}</option>@endforeach
            </select></div>
        <div><label class="label">Posts per day</label><input type="number" name="daily_limit" min="0" max="50" value="{{ old('daily_limit', $rule->daily_limit) }}" class="input"></div>

        <div><label class="label">Slot times (HH:MM, comma separated)</label>
            <input name="slot_times" value="{{ old('slot_times', implode(', ', $rule->slot_times ?? [])) }}" class="input" placeholder="08:30, 11:00, 13:30, 16:30, 20:00">
            <p class="help">Leave empty to spread posts evenly inside the window.</p></div>
        <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Window start</label><input name="window_start" value="{{ old('window_start', substr($rule->window_start, 0, 5)) }}" class="input" placeholder="08:00"></div>
            <div><label class="label">Window end</label><input name="window_end" value="{{ old('window_end', substr($rule->window_end, 0, 5)) }}" class="input" placeholder="21:00"></div>
        </div>

        <div><label class="label">Minimum minutes between posts</label><input type="number" name="min_interval_minutes" min="0" value="{{ old('min_interval_minutes', $rule->min_interval_minutes) }}" class="input"></div>
        <div>
            <label class="label">Active days</label>
            <div class="flex flex-wrap gap-3 text-sm">
                @foreach([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $n => $d)
                    <label class="flex items-center gap-1"><input type="checkbox" name="active_weekdays[]" value="{{ $n }}" @checked(in_array($n, old('active_weekdays', $rule->active_weekdays ?? [1,2,3,4,5,6,7])))> {{ $d }}</label>
                @endforeach
            </div>
        </div>

        <div><label class="label">Weekend posts per day (optional)</label><input type="number" name="weekend_daily_limit" min="0" value="{{ old('weekend_daily_limit', $rule->weekend_daily_limit) }}" class="input"></div>
        <div><label class="label">Weekend slot times (optional)</label><input name="weekend_slot_times" value="{{ old('weekend_slot_times', implode(', ', $rule->weekend_slot_times ?? [])) }}" class="input"></div>

        <div class="grid grid-cols-2 gap-3">
            <div><label class="label">Max posts…</label><input type="number" name="max_posts_per_period" min="1" value="{{ old('max_posts_per_period', $rule->max_posts_per_period) }}" class="input"></div>
            <div><label class="label">…per hours</label><input type="number" name="period_hours" min="1" value="{{ old('period_hours', $rule->period_hours) }}" class="input"></div>
        </div>
        <div><label class="label">Video order</label>
            <select name="selection_order" class="input">
                <option value="oldest_first" @selected($rule->selection_order === 'oldest_first')>Oldest uploaded first</option>
                <option value="newest_first" @selected($rule->selection_order === 'newest_first')>Newest uploaded first</option>
                <option value="random" @selected($rule->selection_order === 'random')>Random</option>
            </select></div>

        <div class="md:col-span-2"><label class="label">Only these folders (one per line, includes sub-folders; empty = all)</label>
            <textarea name="folder_filter" rows="3" class="input">{{ old('folder_filter', implode("\n", $rule->folder_filter ?? [])) }}</textarea></div>

        <div><label class="label">Max attempts per post</label><input type="number" name="max_attempts" min="1" max="20" value="{{ old('max_attempts', $rule->max_attempts) }}" class="input"></div>
        @if($account->platform === \App\Enums\Platform::YouTube)
            <div><label class="label">YouTube visibility</label>
                <select name="privacy_status" class="input">
                    @foreach(['public', 'unlisted', 'private'] as $p)<option value="{{ $p }}" @selected(($rule->privacy_status ?? 'public') === $p)>{{ ucfirst($p) }}</option>@endforeach
                </select></div>
        @endif

        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="auto_retry" value="1" @checked(old('auto_retry', $rule->auto_retry))> Retry temporary failures automatically</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allow_reuse" value="1" @checked(old('allow_reuse', $rule->allow_reuse))> When all videos are used, re-post older ones</label>

        <div class="md:col-span-2"><button class="btn btn-primary">Save rule</button></div>
    </form>
</x-layout>
