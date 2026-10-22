@props(['value' => null, 'color' => null])
@php
    $value = $value instanceof \BackedEnum ? $value->value : $value;
    $map = [
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'red' => 'bg-red-50 text-red-700 ring-red-600/20',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-600/20',
        'orange' => 'bg-orange-50 text-orange-700 ring-orange-600/20',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-600/20',
        'indigo' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
        'gray' => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    ];
    $auto = [
        'published' => 'green', 'ready' => 'green', 'connected' => 'green', 'active' => 'green', 'completed' => 'green', 'valid' => 'green', 'approved' => 'green', 'succeeded' => 'green', 'sent' => 'green', 'online' => 'green',
        'failed' => 'red', 'revoked' => 'red', 'expired' => 'red', 'rejected' => 'red', 'error' => 'red', 'ineligible' => 'red',
        'retrying' => 'orange', 'low_confidence' => 'orange', 'warning' => 'orange', 'offline' => 'orange',
        'publishing' => 'amber', 'processing' => 'amber', 'uploading' => 'amber', 'generating' => 'amber', 'paused' => 'amber', 'pending' => 'amber', 'running' => 'amber',
        'scheduled' => 'blue', 'uploaded' => 'blue', 'discovered' => 'blue', 'edited' => 'indigo', 'info' => 'gray',
    ];
    $classes = $map[$color ?? ($auto[$value] ?? 'gray')] ?? $map['gray'];
@endphp
<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset $classes"]) }}>{{ $slot->isEmpty() ? str_replace('_', ' ', (string) $value) : $slot }}</span>
