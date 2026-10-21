@props([
    'variant' => 'bar', // bar : en tête d'un écran de travail · column : dans la colonne d'un écran de lecture
    'space' => null, // la clé de l'espace ; déduite de la route si on ne la donne pas
])

@php
    // Les onglets d'un espace. L'espace est nommé dans la barre latérale ; ici
    // on ne répète donc pas son nom, on donne juste les portes voisines — et
    // leurs chiffres exacts, puisque c'est là qu'on agit dessus.
    //
    // La page déclare son espace. Le déduire de la requête marchait au
    // chargement et seulement là : au premier rendu Livewire — un clic, une
    // validation, un `wire:poll` — la route courante devient `livewire.update`,
    // plus aucun onglet ne correspondait, et la barre disparaissait de l'écran.
    $resolved = \App\Support\Navigation\Spaces::named($space) ?? \App\Support\Navigation\Spaces::current();
    $tabs = $resolved === null ? [] : $resolved['tabs'];
@endphp

@if (count($tabs) > 1)
    <nav
        aria-label="{{ $resolved['label'] }}"
        {{ $attributes->class([
            'flex gap-1' => $variant === 'bar',
            'flex flex-col gap-0.5' => $variant === 'column',
        ]) }}
    >
        @foreach ($tabs as $tab)
            @php $isCurrent = \App\Support\Navigation\CurrentRoute::is($tab['route'], ...$tab['also']); @endphp
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
