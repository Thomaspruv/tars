@props(['priority'])

@php
    $value = $priority instanceof \App\Enums\TaskPriority ? $priority->value : $priority;

    $styles = [
        'p1' => 'bg-(--dgrbg) text-(--dgr)',
        'p2' => 'bg-(--warnbg) text-(--warn)',
        'p3' => 'bg-(--surf2) text-(--mut)',
    ];
@endphp

@if ($value && isset($styles[$value]))
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] px-1.5 py-0.5 font-mono text-[10.5px] font-semibold uppercase '.$styles[$value]]) }}>
        {{ strtoupper($value) }}
    </span>
@endif
