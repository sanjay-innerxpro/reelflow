@props([
    'rows' => [],          // [['label' => 'Mon 12', 'values' => ['succeeded' => 3, ...]], ...]
    'series' => [],        // ['succeeded' => ['label' => 'Succeeded', 'mark' => 'good'], ...]
    'empty' => 'Nothing recorded yet.',
    'caption' => 'Stacked bars over time',
])
@php
    $max = max(1, collect($rows)->max(fn ($r) => array_sum($r['values'])) ?? 1);
    $total = collect($rows)->sum(fn ($r) => array_sum($r['values']));

    // One viewBox unit is one pixel at full width; the SVG then scales down.
    // Narrow the drawing for few columns so three bars do not float in a
    // 760-unit field; the SVG is centred by the default preserveAspectRatio.
    $w = min(760, max(240, count($rows) * 56)); $h = 190; $padBottom = 22; $padTop = 16;
    $plot = $h - $padBottom - $padTop;
    $step = $w / max(1, count($rows));
    $barW = min(34, $step * 0.62);
@endphp

<figure class="chart text-slate-900" {{ $attributes }}>
    <figcaption class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
        @foreach($series as $key => $s)
            <span class="inline-flex items-center gap-1.5"><span class="swatch swatch-{{ $s['mark'] }}"></span>{{ $s['label'] }}</span>
        @endforeach
    </figcaption>

    @if($total === 0)
        <p class="flex h-40 items-center justify-center text-sm text-slate-500">{{ $empty }}</p>
    @else
        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="mt-2 h-48 w-full" role="img" aria-label="{{ $caption }}">
            {{-- Baseline only: a recessive grid, no box. --}}
            <line x1="0" y1="{{ $padTop + $plot }}" x2="{{ $w }}" y2="{{ $padTop + $plot }}" class="chart-grid" stroke-width="1" />

            @foreach($rows as $i => $row)
                @php
                    $x = $i * $step + ($step - $barW) / 2;
                    $rowTotal = array_sum($row['values']);
                    $y = $padTop + $plot;
                    $segments = [];
                    foreach ($series as $key => $s) {
                        $value = $row['values'][$key] ?? 0;
                        if ($value <= 0) { continue; }
                        $height = $value / $max * $plot;
                        $y -= $height;
                        $segments[] = ['y' => $y, 'h' => $height, 'mark' => $s['mark'], 'label' => $s['label'], 'value' => $value];
                        $y -= 2; // 2px surface gap between stacked segments
                    }
                @endphp
                <g class="chart-col">
                    <rect x="{{ $i * $step }}" y="{{ $padTop }}" width="{{ $step }}" height="{{ $plot }}" class="chart-hit" rx="6" />
                    @foreach($segments as $j => $seg)
                        <rect x="{{ $x }}" y="{{ round($seg['y'], 2) }}" width="{{ $barW }}" height="{{ max(2, round($seg['h'], 2)) }}"
                              rx="{{ $j === count($segments) - 1 ? 4 : 1 }}" class="mark-{{ $seg['mark'] }}" />
                    @endforeach
                    @if($rowTotal > 0)
                        <text x="{{ $x + $barW / 2 }}" y="{{ round(end($segments)['y'] - 5, 2) }}" text-anchor="middle" class="chart-axis">{{ $rowTotal }}</text>
                    @endif
                    <text x="{{ $i * $step + $step / 2 }}" y="{{ $h - 6 }}" text-anchor="middle" class="chart-axis">{{ $row['label'] }}</text>
                    <title>{{ $row['label'] }}: {{ collect($series)->map(fn ($s, $k) => ($row['values'][$k] ?? 0).' '.strtolower($s['label']))->implode(', ') }}</title>
                </g>
            @endforeach
        </svg>
    @endif
</figure>
