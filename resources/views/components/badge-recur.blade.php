@props(['recurrence'])

@php
    $days = ['mon' => 'lun', 'tue' => 'mar', 'wed' => 'mer', 'thu' => 'jeu', 'fri' => 'ven', 'sat' => 'sam', 'sun' => 'dim'];

    $label = match (true) {
        $recurrence === null => null,
        $recurrence === 'daily' => 'quotidien',
        str_starts_with($recurrence, 'weekly:') => 'hebdo · '.($days[substr($recurrence, 7)] ?? substr($recurrence, 7)),
        $recurrence === 'weekly' => 'hebdo',
        str_starts_with($recurrence, 'monthly:') => 'mensuel · '.substr($recurrence, 8),
        $recurrence === 'monthly' => 'mensuel',
        str_starts_with($recurrence, 'yearly:') => 'annuel · '.substr($recurrence, 7),
        $recurrence === 'yearly' => 'annuel',
        default => $recurrence,
    };
@endphp

@if ($label)
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[10px] text-(--mut)']) }}>
        <span aria-hidden="true">↻</span>{{ $label }}
    </span>
@endif
