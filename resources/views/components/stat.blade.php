@props(['label', 'value', 'href' => null, 'tone' => 'slate', 'icon' => null, 'hint' => null])
@php
    $tones = [
        'slate' => ['text-slate-900', 'bg-indigo-50 text-indigo-600'],
        'red' => ['text-red-600', 'bg-red-50 text-red-600'],
        'green' => ['text-slate-900', 'bg-emerald-50 text-emerald-600'],
        'amber' => ['text-amber-600', 'bg-amber-50 text-amber-600'],
    ];
    [$valueClass, $iconClass] = $tones[$tone] ?? $tones['slate'];
@endphp
<a @if($href) href="{{ $href }}" @endif class="card card-body group block transition hover:-translate-y-0.5 hover:shadow-(--shadow-lift)">
    <div class="flex items-start justify-between gap-3">
        <div class="text-sm font-medium text-slate-500">{{ $label }}</div>
        @if($icon)<span class="flex h-9 w-9 items-center justify-center rounded-xl {{ $iconClass }}"><x-icon :name="$icon" class="h-5 w-5" /></span>@endif
    </div>
    <div class="mt-2 text-3xl font-semibold tracking-tight {{ $valueClass }}">{{ number_format((int) $value) }}</div>
    @if($hint)<div class="mt-1 text-xs text-slate-500">{{ $hint }}</div>@endif
</a>
