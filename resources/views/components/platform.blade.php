@props(['platform'])
@php
    $p = $platform instanceof \App\Enums\Platform ? $platform : \App\Enums\Platform::from($platform);
    $styles = [
        'facebook' => 'bg-blue-600',
        'instagram' => 'bg-gradient-to-br from-fuchsia-500 to-orange-400',
        'youtube' => 'bg-red-600',
    ];
    $letters = ['facebook' => 'f', 'instagram' => 'ig', 'youtube' => '▶'];
@endphp
<span title="{{ $p->label() }}" {{ $attributes->merge(['class' => 'inline-flex h-5 min-w-5 items-center justify-center rounded px-1 text-[10px] font-bold text-on-color '.$styles[$p->value]]) }}>{{ $letters[$p->value] }}</span>
