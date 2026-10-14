@props([
    'type' => 'status', // status | priority | entity | goal | recur | delegable | agent-run | token | pill
    'value' => null,
    'label' => null,
    'entity' => null,
    'goal' => null,
    'name' => null,
    'tag' => null,
    'recurrence' => null,
    'kind' => null, // type=token only: date | entity | goal | priority
    'tone' => 'neutral', // type=pill only: neutral | mine | agent | warn | danger | sunk
])

@php
    $resolvedValue = $value instanceof \BackedEnum ? $value->value : $value;

    // Toutes les pastilles du système sont des pilules à 999px. Deux tons
    // portent du sens : l'abricot est mon action / mon filtre actif, le vert
    // est l'agent. Le reste est neutre.
    $pillTones = [
        'neutral' => 'bg-(--surf2) text-(--tx)',
        'mine' => 'bg-(--acbg) text-(--ac) font-semibold',
        'agent' => 'bg-(--aibg) text-(--ai) font-semibold',
        'warn' => 'bg-(--warnbg) text-(--warn)',
        'danger' => 'bg-(--dgrbg) text-(--dgr)',
        'sunk' => 'bg-(--sunk) text-(--mut)',
    ];
    $pill = 'inline-flex items-center gap-1.5 rounded-full px-[11px] py-[4px] text-[12px]';
@endphp

@if ($type === 'pill')
    <span {{ $attributes->merge(['class' => $pill.' '.($pillTones[$tone] ?? $pillTones['neutral'])]) }}>
        {{ $slot->isEmpty() ? ($label ?? $resolvedValue) : $slot }}
    </span>
@elseif ($type === 'priority')
    @php
        $styles = [
            'p1' => 'bg-(--acbg) text-(--ac)',
            'p2' => 'bg-(--warnbg) text-(--warn)',
            'p3' => 'bg-(--surf2) text-(--mut)',
        ];
    @endphp
    @if ($resolvedValue && isset($styles[$resolvedValue]))
        <span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center rounded-full px-[9px] py-[3px] text-[11px] font-semibold uppercase '.$styles[$resolvedValue]]) }}>
            {{ strtoupper($resolvedValue) }}
        </span>
    @endif
@elseif ($type === 'status')
    @php
        $map = [
            'active' => ['bg-(--aibg) text-(--ai)', 'En cours'],
            'paused' => ['bg-(--surf2) text-(--mut)', 'En pause'],
            'done' => ['bg-(--aibg) text-(--ai)', 'Atteint'],
            'abandoned' => ['bg-(--surf2) text-(--mut)', 'Abandonné'],
            'archived' => ['bg-(--surf2) text-(--mut)', 'Archivé'],
            'late' => ['bg-(--warnbg) text-(--warn)', 'En retard'],
            'synced' => ['bg-(--aibg) text-(--ai)', 'À jour'],
            'behind' => ['bg-(--warnbg) text-(--warn)', 'En retard sur le remote'],
            'conflict' => ['bg-(--dgrbg) text-(--dgr)', 'Conflit'],
            'unknown' => ['bg-(--surf2) text-(--mut)', 'Inconnu'],
            'success' => ['bg-(--aibg) text-(--ai)', 'Réussi'],
            'error' => ['bg-(--dgrbg) text-(--dgr)', 'Erreur'],
            'ambiguous' => ['bg-(--warnbg) text-(--warn)', 'Ambigu'],
            'failed' => ['bg-(--dgrbg) text-(--dgr)', 'Échoué'],
        ];
        [$style, $defaultLabel] = $map[$resolvedValue] ?? ['bg-(--surf2) text-(--mut)', ucfirst((string) $resolvedValue)];
    @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-[10px] py-[3px] text-[12px] font-medium '.$style]) }}>
        {{ $label ?? $defaultLabel }}
    </span>
@elseif ($type === 'agent-run')
    @php
        $map = [
            'success' => ['text-(--ai)', '✓', false],
            'running' => ['text-(--ai)', '●', true],
            'failed' => ['text-(--dgr)', '✕', false],
        ];
        [$style, $glyph, $pulse] = $map[$resolvedValue] ?? ['text-(--mut)', '●', false];
    @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-[13px] '.$style]) }}>
        <span aria-hidden="true" class="{{ $pulse ? 'animate-pulse-soft' : '' }}">{{ $glyph }}</span>{{ $label ?? '' }}
    </span>
@elseif ($type === 'entity')
    @php $entityLabel = $name ?? $entity?->name; @endphp
    @if ($entityLabel)
        @if ($entity)
            <a href="{{ route('entities.show', $entity) }}" wire:navigate {{ $attributes->merge(['class' => $pill.' bg-(--surf2) text-(--mut) hover:text-(--tx)']) }}>{{ $entityLabel }}</a>
        @else
            <span {{ $attributes->merge(['class' => $pill.' bg-(--surf2) text-(--mut)']) }}>{{ $entityLabel }}</span>
        @endif
    @endif
@elseif ($type === 'goal')
    {{-- Un objectif se lit à sa pastille de teinte + son nom, jamais à la
         couleur seule : c'est la règle du système pour toute la palette
         catégorielle. --}}
    @php $goalLabel = $tag ?? $goal?->title; @endphp
    @if ($goalLabel)
        @if ($goal)
            <a href="{{ route('goals.show', $goal) }}" wire:navigate {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 text-[13px] text-(--mut) hover:text-(--tx)']) }}>
                <span class="size-[8px] shrink-0 rounded-full" style="background: var(--goal-{{ $goal->color_slot }})"></span>{{ $goalLabel }}
            </a>
        @else
            <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 text-[13px] text-(--mut)']) }}>{{ $goalLabel }}</span>
        @endif
    @endif
@elseif ($type === 'recur')
    @php
        $days = ['mon' => 'lundi', 'tue' => 'mardi', 'wed' => 'mercredi', 'thu' => 'jeudi', 'fri' => 'vendredi', 'sat' => 'samedi', 'sun' => 'dimanche'];

        // Dit en français dans le texte, jamais en jargon : le mono a disparu
        // du système sauf dans le Cerveau et sur l'écran Agents.
        $recurLabel = match (true) {
            $recurrence === null => null,
            $recurrence === 'daily' => 'tous les jours',
            str_starts_with($recurrence, 'weekly:') => 'tous les '.($days[substr($recurrence, 7)] ?? substr($recurrence, 7)),
            $recurrence === 'weekly' => 'toutes les semaines',
            str_starts_with($recurrence, 'monthly:') => 'tous les mois, le '.substr($recurrence, 8),
            $recurrence === 'monthly' => 'tous les mois',
            str_starts_with($recurrence, 'yearly:') => 'tous les ans, le '.substr($recurrence, 7),
            $recurrence === 'yearly' => 'tous les ans',
            default => $recurrence,
        };
    @endphp
    @if ($recurLabel)
        <span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center gap-1.5 text-[12px] text-(--mut)']) }}>
            <span aria-hidden="true">↻</span>{{ $recurLabel }}
        </span>
    @endif
@elseif ($type === 'delegable')
    <span {{ $attributes->merge(['class' => $pill.' bg-(--aibg) text-(--ai)']) }}>délégable</span>
@elseif ($type === 'token')
    @php
        $tokenStyles = [
            'date' => 'bg-(--acbg) text-(--ac)',
            'entity' => 'bg-(--surf2) text-(--tx)',
            'goal' => 'bg-(--acbg) text-(--ac)',
            'priority' => match ($resolvedValue) {
                'p1' => 'bg-(--acbg) text-(--ac)',
                'p2' => 'bg-(--warnbg) text-(--warn)',
                default => 'bg-(--surf2) text-(--mut)',
            },
        ];
    @endphp
    <span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-[11px] py-[4px] text-[13px] '.($tokenStyles[$kind] ?? 'bg-(--surf2) text-(--mut)')]) }}>
        {{ $label ?? $resolvedValue }}
    </span>
@endif
