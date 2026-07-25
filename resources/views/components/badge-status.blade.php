@props(['status', 'label' => null])

@php
    $value = $status instanceof \BackedEnum ? $status->value : $status;

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
    ];

    [$style, $defaultLabel] = $map[$value] ?? ['bg-(--surf2) text-(--mut)', ucfirst((string) $value)];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-medium '.$style]) }}>
    {{ $label ?? $defaultLabel }}
</span>
