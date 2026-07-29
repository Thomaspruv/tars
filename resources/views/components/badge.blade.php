@props([
    'type' => 'status', // status | priority | entity | goal | recur | delegable | agent-run | token
    'value' => null,
    'label' => null,
    'entity' => null,
    'goal' => null,
    'name' => null,
    'tag' => null,
    'recurrence' => null,
    'kind' => null, // type=token only: date | entity | goal | priority
])

@php
    $resolvedValue = $value instanceof \BackedEnum ? $value->value : $value;
@endphp

@if ($type === 'priority')
    @php
        $styles = [
            'p1' => 'bg-(--dgrbg) text-(--dgr)',
            'p2' => 'bg-(--warnbg) text-(--warn)',
            'p3' => 'bg-(--surf2) text-(--mut)',
        ];
    @endphp
    @if ($resolvedValue && isset($styles[$resolvedValue]))
        <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] px-[7px] py-[3px] font-mono text-[10.5px] font-semibold uppercase '.$styles[$resolvedValue]]) }}>
            {{ strtoupper($resolvedValue) }}
        </span>
    @endif
@elseif ($type === 'status')
    @php
        $map = [
            'active' => ['bg-(--okbg) text-(--ok)', 'Actif'],
            'paused' => ['bg-(--surf2) text-(--mut)', 'En pause'],
            'done' => ['bg-(--acbg) text-(--ac)', 'Terminé'],
            'abandoned' => ['bg-(--surf2) text-(--mut)', 'Abandonné'],
            'archived' => ['bg-(--surf2) text-(--mut)', 'Archivé'],
            'late' => ['bg-(--warnbg) text-(--warn)', 'En retard'],
            'synced' => ['bg-(--okbg) text-(--ok)', 'À jour'],
            'behind' => ['bg-(--warnbg) text-(--warn)', 'En retard sur le remote'],
            'conflict' => ['bg-(--dgrbg) text-(--dgr)', 'Conflit'],
            'unknown' => ['bg-(--surf2) text-(--mut)', 'Inconnu'],
            'success' => ['bg-(--okbg) text-(--ok)', 'Réussi'],
            'error' => ['bg-(--dgrbg) text-(--dgr)', 'Erreur'],
            'ambiguous' => ['bg-(--warnbg) text-(--warn)', 'Ambigu'],
            'failed' => ['bg-(--dgrbg) text-(--dgr)', 'Échoué'],
        ];
        [$style, $defaultLabel] = $map[$resolvedValue] ?? ['bg-(--surf2) text-(--mut)', ucfirst((string) $resolvedValue)];
    @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-[10px] py-[3px] text-[11px] font-medium '.$style]) }}>
        {{ $label ?? $defaultLabel }}
    </span>
@elseif ($type === 'agent-run')
    @php
        $map = [
            'success' => ['text-(--ok)', '✓', false],
            'running' => ['text-(--ai)', '●', true],
            'failed' => ['text-(--dgr)', '✕', false],
        ];
        [$style, $glyph, $pulse] = $map[$resolvedValue] ?? ['text-(--mut)', '●', false];
    @endphp
    {{-- Mono badges keep the raw machine-produced word (not a French label) —
         "le mono signale la machine" (§0 de la spec v2). --}}
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 font-mono text-[10.5px] '.$style]) }}>
        <span aria-hidden="true" class="{{ $pulse ? 'animate-pulse-soft' : '' }}">{{ $glyph }}</span>{{ $label ?? $resolvedValue }}
    </span>
@elseif ($type === 'entity')
    @php $entityLabel = $name ?? $entity?->name; @endphp
    @if ($entityLabel)
        @if ($entity)
            <a href="{{ route('entities.show', $entity) }}" wire:navigate {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--bd2) px-2 py-0.5 text-[11px] text-(--mut) hover:text-(--tx)']) }}>{{ '@'.$entityLabel }}</a>
        @else
            <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--bd2) px-2 py-0.5 text-[11px] text-(--mut)']) }}>{{ '@'.$entityLabel }}</span>
        @endif
    @endif
@elseif ($type === 'goal')
    @php $goalLabel = $tag ?? $goal?->tag; @endphp
    @if ($goalLabel)
        @if ($goal)
            <a href="{{ route('goals.show', $goal) }}" wire:navigate {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--ac)/35 px-2 py-0.5 text-[11px] text-(--ac) hover:border-(--ac)']) }}>{{ '#'.$goalLabel }}</a>
        @else
            <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--ac)/35 px-2 py-0.5 text-[11px] text-(--ac)']) }}>{{ '#'.$goalLabel }}</span>
        @endif
    @endif
@elseif ($type === 'recur')
    @php
        $days = ['mon' => 'lun', 'tue' => 'mar', 'wed' => 'mer', 'thu' => 'jeu', 'fri' => 'ven', 'sat' => 'sam', 'sun' => 'dim'];

        $recurLabel = match (true) {
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
    @if ($recurLabel)
        <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-[5px] bg-(--surf2) px-1.5 py-0.5 font-mono text-[10px] text-(--mut)']) }}>
            <span aria-hidden="true">↻</span>{{ $recurLabel }}
        </span>
    @endif
@elseif ($type === 'delegable')
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[5px] border border-(--ai)/35 px-2 py-0.5 text-[11px] text-(--ai)']) }}>délégable</span>
@elseif ($type === 'token')
    @php
        $tokenStyles = [
            'date' => 'bg-(--acbg) text-(--ac)',
            'entity' => 'border border-(--bd2) text-(--mut)',
            'goal' => 'bg-(--acbg) text-(--ac)',
            'priority' => match ($resolvedValue) {
                'p1' => 'bg-(--dgrbg) text-(--dgr)',
                'p2' => 'bg-(--warnbg) text-(--warn)',
                default => 'bg-(--surf2) text-(--mut)',
            },
        ];
    @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-[4px] px-[5px] py-px font-mono text-[12.5px] '.($tokenStyles[$kind] ?? 'bg-(--surf2) text-(--mut)')]) }}>
        {{ $label ?? $resolvedValue }}
    </span>
@endif
