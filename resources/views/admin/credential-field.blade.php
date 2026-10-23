@php
    $C = \App\Support\Credentials::class;
    $name = $C::inputName($key);
    $type = $field['type'] ?? 'text';
    $secret = $C::isSecret($field);
    $stored = $credentials->stored($key);
    $env = $credentials->envValue($key);
    $envSet = is_array($env) ? $env !== [] : filled($env) || is_bool($env);
    $envOption = is_bool($env) ? ($env ? '1' : '0') : (string) (is_array($env) ? '' : $env);
    $envShown = match (true) {
        ! $envSet => null,
        $type === 'keys' => count($env).' '.\Illuminate\Support\Str::plural('key', count($env)).', first '.$C::mask($env[0]),
        $secret => $C::mask($env),
        $type === 'select' => $field['options'][$envOption] ?? $envOption,
        default => $envOption,
    };
    $savedShown = match (true) {
        $stored === null => null,
        $type === 'keys' => count($C::splitKeys($stored)).' '.\Illuminate\Support\Str::plural('key', count($C::splitKeys($stored))).', first '.$C::mask($C::splitKeys($stored)[0] ?? ''),
        default => $C::mask($stored),
    };
    $change = $updated[$key] ?? null;
@endphp
<div @class(['md:col-span-2' => $type === 'keys'])>
    <div class="mb-1.5 flex items-center justify-between gap-2">
        <label for="{{ $name }}" class="label mb-0">{{ $field['label'] }}</label>
        @if($credentials->isUnreadable($key))
            <x-badge color="red" title="Saved with a different APP_KEY; enter it again">re-enter</x-badge>
        @elseif($stored !== null)
            <x-badge color="green">saved here</x-badge>
        @elseif($envSet)
            <x-badge color="gray">from .env</x-badge>
        @else
            <x-badge color="amber">not set</x-badge>
        @endif
    </div>

    @if($type === 'select')
        <select id="{{ $name }}" name="{{ $name }}" class="input">
            <option value="">{{ $envSet ? 'Use .env ('.$envShown.')' : 'Default' }}</option>
            @foreach($field['options'] as $value => $label)
                <option value="{{ $value }}" @selected((string) old($name, $stored) === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>
    @elseif($type === 'keys')
        <textarea id="{{ $name }}" name="{{ $name }}" rows="3" class="input font-mono text-xs" autocomplete="off" spellcheck="false"
                  placeholder="{{ $stored !== null ? 'Leave empty to keep the saved keys ('.$savedShown.')' : ($envSet ? 'Leave empty to use .env ('.$envShown.')' : 'Paste one key per line') }}"></textarea>
    @elseif($secret)
        <input type="password" id="{{ $name }}" name="{{ $name }}" class="input font-mono" autocomplete="new-password" spellcheck="false"
               placeholder="{{ $stored !== null ? 'Leave empty to keep '.$savedShown : ($envSet ? 'Leave empty to use .env ('.$envShown.')' : '') }}">
    @else
        <input type="{{ in_array($type, ['url', 'email'], true) ? $type : 'text' }}" id="{{ $name }}" name="{{ $name }}" value="{{ old($name, $stored) }}" class="input" spellcheck="false"
               placeholder="{{ $envSet ? '.env: '.$envShown : (isset($field['callback']) ? $C::callbackUrl($field['callback']) : ($field['placeholder'] ?? '')) }}">
    @endif

    @if($secret && $stored !== null)
        <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
            <span>Saved: <code>{{ $savedShown }}</code></span>
            <label class="flex items-center gap-1.5"><input type="checkbox" name="clear[]" value="{{ $key }}"> Remove{{ $envSet ? ' and use .env' : '' }}</label>
        </div>
    @endif

    @isset($field['callback'])
        <div class="mt-1.5 flex items-center gap-2 text-xs text-slate-500">
            <span class="shrink-0">Register:</span>
            <code id="cb-{{ $name }}" class="min-w-0 flex-1 truncate">{{ config($field['config']) }}</code>
            <button type="button" class="btn btn-sm" data-copy="#cb-{{ $name }}">Copy</button>
        </div>
    @endisset

    @isset($field['help'])<p class="help">{{ $field['help'] }}</p>@endisset

    @if($change)
        <p class="help">Saved <x-time :at="$change->updated_at" />{{ $change->updatedBy ? ' by '.$change->updatedBy->name : '' }}</p>
    @endif
</div>
