<x-layout title="AI settings">
    <x-slot:header><h1 class="text-xl font-semibold">AI captions</h1></x-slot:header>

    <div class="grid gap-6 lg:grid-cols-3">
        <form method="POST" action="{{ route('settings.ai.update') }}" class="card card-body space-y-4 lg:col-span-2">
            @csrf @method('PUT')
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="auto_generate" value="1" @checked($settings['ai.auto_generate'])> Generate captions automatically after each upload</label>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="transcribe_audio" value="1" @checked($settings['ai.transcribe_audio'])> Transcribe audio for extra context (needs TRANSCRIPTION_PROVIDER)</label>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="label">Caption language</label>
                    <select name="language" class="input">
                        @foreach(['auto' => 'Match the video', 'en' => 'English', 'hi' => 'Hindi', 'hinglish' => 'Hinglish'] as $v => $l)
                            <option value="{{ $v }}" @selected($settings['ai.language'] === $v)>{{ $l }}</option>
                        @endforeach
                    </select></div>
                <div><label class="label">Tone</label><input name="tone" value="{{ $settings['ai.tone'] }}" class="input"></div>
            </div>
            <div><label class="label">Target audience</label><input name="audience" value="{{ $settings['ai.audience'] }}" class="input" placeholder="e.g. Indian home cooks aged 20–40"></div>
            <div><label class="label">Brand / creator context (facts the AI may rely on)</label><textarea name="brand_context" rows="2" class="input">{{ $settings['ai.brand_context'] }}</textarea></div>
            <details class="rounded-lg border border-slate-200 p-3 text-sm">
                <summary class="cursor-pointer font-medium">Custom system prompt (advanced)</summary>
                <p class="help">Leave empty to use the built-in prompt version <strong>{{ config('reelflow.ai.prompt_version') }}</strong>. The JSON schema is always enforced and validated.</p>
                <div class="mt-2"><label class="label">Prompt version label</label><input name="custom_prompt_version" value="{{ $settings['ai.custom_prompt_version'] }}" class="input w-40" placeholder="custom-1"></div>
                <textarea name="custom_prompt" rows="10" class="input mt-2 font-mono text-xs" placeholder="{{ $defaultPrompt }}">{{ $settings['ai.custom_prompt'] }}</textarea>
            </details>
            <button class="btn btn-primary">Save</button>
        </form>

        <div class="card card-body h-fit text-sm">
            <h2 class="card-title mb-2">Provider (from .env)</h2>
            <dl class="grid grid-cols-2 gap-y-1">
                <dt class="text-slate-500">Status</dt><dd><x-badge :value="$configured ? 'active' : 'failed'">{{ $configured ? 'configured' : 'not configured' }}</x-badge></dd>
                <dt class="text-slate-500">Provider</dt><dd>{{ $provider }}
                    @if($isFree === true)<x-badge color="green">free</x-badge>@elseif($isFree === false)<x-badge color="red">paid</x-badge>@else<x-badge color="gray">check pricing</x-badge>@endif</dd>
                <dt class="text-slate-500">Model</dt><dd>{{ $model ?: ($provider === 'anthropic' ? \App\Services\Ai\Providers\AnthropicProvider::DEFAULT_MODEL : '—') }}</dd>
                <dt class="text-slate-500">Prompt</dt><dd>{{ $promptVersion }}</dd>
                <dt class="text-slate-500">Transcription</dt><dd>{{ $transcription }}</dd>
                <dt class="text-slate-500">Spent today</dt><dd>${{ number_format($spentToday, 4) }}{{ $budget !== null ? ' / $'.number_format($budget, 2) : '' }}</dd>
            </dl>
            <p class="help">Free options: <strong>gemini</strong> (Google AI Studio free tier), <strong>groq</strong> (free tier) or <strong>ollama</strong> (self-hosted, no key). Free tiers have daily request limits; captions are generated once per video, so normal use stays well inside them. Cost is only estimated when AI_INPUT_COST_PER_MTOK / AI_OUTPUT_COST_PER_MTOK are set.</p>
        </div>
    </div>

    <div class="card mt-6 overflow-x-auto">
        <div class="border-b border-slate-100 px-5 py-3"><h2 class="card-title">Recent generations</h2></div>
        <table class="table">
            <thead><tr><th>When</th><th>Video</th><th>Status</th><th>Confidence</th><th>Model</th><th>Time</th><th>Cost</th></tr></thead>
            <tbody>
            @forelse($recent as $gen)
                <tr>
                    <td class="text-xs whitespace-nowrap"><x-time :at="$gen->created_at" /></td>
                    <td>@if($gen->video)<a href="{{ route('videos.show', $gen->video) }}" class="link">{{ \Illuminate\Support\Str::limit($gen->video->original_filename, 30) }}</a>@endif</td>
                    <td><x-badge :value="$gen->status" /></td>
                    <td>{{ $gen->confidence !== null ? number_format($gen->confidence * 100).'%' : '—' }}</td>
                    <td class="text-xs">{{ $gen->model }}</td>
                    <td class="text-xs">{{ $gen->duration_ms ? round($gen->duration_ms / 1000, 1).'s' : '—' }}</td>
                    <td class="text-xs">{{ $gen->cost_usd !== null ? '$'.number_format($gen->cost_usd, 4) : 'n/a' }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-slate-500">No generations yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-layout>
