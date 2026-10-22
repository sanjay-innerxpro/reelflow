@props(['label', 'value', 'href' => null, 'tone' => 'slate'])
@php
    $tones = ['slate' => 'text-slate-900', 'red' => 'text-red-600', 'green' => 'text-emerald-600', 'amber' => 'text-amber-600'];
@endphp
<a @if($href) href="{{ $href }}" @endif class="card card-body block transition hover:border-indigo-300">
    <div class="text-xs font-medium text-slate-500">{{ $label }}</div>
    <div class="mt-1 text-2xl font-semibold {{ $tones[$tone] ?? $tones['slate'] }}">{{ $value }}</div>
</a>
