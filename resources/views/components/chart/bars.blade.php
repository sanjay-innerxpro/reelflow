@props([
    // [['label' => …, 'value' => 12, 'hint' => …, 'mark' => 'magnitude', 'ratio' => 0.4], ...]
    // 'ratio' (0-1) sets the bar length when it should show a share of
    // something — a storage limit — rather than compare rows to each other.
    'rows' => [],
    'format' => null,  // optional fn (int|float) => string for the direct label
    'empty' => 'Nothing recorded yet.',
])
@php
    $max = max(1, collect($rows)->max('value') ?: 1);
    $format ??= fn ($v) => number_format($v);
@endphp

<div class="chart text-slate-900" {{ $attributes }}>
    @forelse($rows as $row)
        @php($pct = max(1.5, min(100, isset($row['ratio']) ? $row['ratio'] * 100 : $row['value'] / $max * 100)))
        <div class="mb-3 last:mb-0">
            <div class="flex items-baseline justify-between gap-3 text-xs">
                <span class="truncate font-medium text-slate-700" @isset($row['hint']) title="{{ $row['hint'] }}" @endisset>{{ $row['label'] }}</span>
                <span class="shrink-0 tabular-nums text-slate-500">{{ $format($row['value']) }}</span>
            </div>
            <div class="mt-1 h-2 overflow-hidden rounded-full chart-track">
                <div class="h-full rounded-full" style="width: {{ round($pct, 1) }}%; background: var(--chart-{{ $row['mark'] ?? 'magnitude' }})"></div>
            </div>
            @isset($row['hint'])<div class="mt-1 truncate text-[11px] text-slate-500">{{ $row['hint'] }}</div>@endisset
        </div>
    @empty
        <p class="py-6 text-center text-sm text-slate-500">{{ $empty }}</p>
    @endforelse
</div>
