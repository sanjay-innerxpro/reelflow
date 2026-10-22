@props(['at', 'format' => 'd M, H:i'])
@if($at)
<time datetime="{{ $at->toIso8601String() }}" title="{{ $at->copy()->utc()->format('Y-m-d H:i') }} UTC" {{ $attributes }}>{{ \App\Support\DisplayTime::format($at, $format) }}</time>
@else
<span class="text-slate-400">—</span>
@endif
