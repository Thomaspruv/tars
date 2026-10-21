@props([
    'treatment' => 'work', // work | reading
    'kicker' => null,
    'title',
    'actions' => null,
])

@php
    // Deux tailles seulement : les écrans de travail titrent à 34px, les
    // écrans de lecture (Revue, Bilan) à 42px. Le sur-titre porte le chiffre
    // du jour — c'est lui qui dit ce qu'on regarde, pas un sous-titre.
    $titleClasses = $treatment === 'reading'
        ? 'font-display text-[42px] font-extrabold leading-[1.1] tracking-[-.03em] text-(--tx)'
        : 'font-display text-[34px] font-extrabold leading-none tracking-[-.03em] text-(--tx)';
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-end gap-4']) }}>
    <div class="min-w-0">
        @if ($kicker)
            <p class="mb-1.5 text-[12px] text-(--mut) kicker">{{ $kicker }}</p>
        @endif
        <h1 class="{{ $titleClasses }}">{{ $title }}</h1>
    </div>

    @isset($actions)
        <div class="ml-auto flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset

    {{ $slot }}
</div>
