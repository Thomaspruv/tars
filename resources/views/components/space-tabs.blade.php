@props([
    'variant' => 'bar', // bar : en tête d'un écran de travail · column : dans la colonne d'un écran de lecture
])

@php
    // Les onglets d'un espace. L'espace est nommé dans la barre latérale ; ici
    // on ne répète donc pas son nom, on donne juste les portes voisines — et
    // leurs chiffres exacts, puisque c'est là qu'on agit dessus.
    $space = \App\Support\Navigation\Spaces::current();
    $tabs = $space === null ? [] : $space['tabs'];
@endphp

@if (count($tabs) > 1)
    <nav
        aria-label="{{ $space['label'] }}"
        {{ $attributes->class([
            'flex gap-1' => $variant === 'bar',
            'flex flex-col gap-0.5' => $variant === 'column',
        ]) }}
    >
        @foreach ($tabs as $tab)
            @php $isCurrent = request()->routeIs($tab['route'], ...$tab['also']); @endphp
            <a
                href="{{ route($tab['route']) }}"
                wire:navigate
                @if ($isCurrent) aria-current="page" @endif
                @class([
                    'press flex items-center gap-2 rounded-full text-[13px]',
                    'px-3.5 py-2' => $variant === 'bar',
                    'px-3 py-2' => $variant === 'column',
                    'bg-(--surf2) font-semibold text-(--tx)' => $isCurrent,
                    'text-(--mut) hover:bg-(--surf) hover:text-(--tx)' => ! $isCurrent,
                ])
            >
                {{ $tab['label'] }}
                <x-nav-signal :key="$tab['signal']" />
            </a>
        @endforeach
    </nav>
@endif
