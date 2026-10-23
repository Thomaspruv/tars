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
    //
    // Trois pastilles, pas trois mots posés sur le fond. Les voisins étaient du
    // texte `--mut` sans contour : rien ne disait qu'« Entités » et « Listes »
    // étaient des endroits où aller, et le courant portait un `--surf2` qui, en
    // clair, se détache du fond de 1,1 de rapport — autant dire rien. Le
    // courant prend donc l'abricot, qui est déjà le « vous êtes ici » de la
    // barre du bas en mobile, et les voisins prennent un contour : un onglet se
    // voit avant de se lire.
    $resolved = \App\Support\Navigation\Spaces::named($space) ?? \App\Support\Navigation\Spaces::current();
    $tabs = $resolved === null ? [] : $resolved['tabs'];
@endphp

@if (count($tabs) > 1)
    <nav
        aria-label="{{ $resolved['label'] }}"
        {{ $attributes->class([
            'flex flex-wrap gap-1.5' => $variant === 'bar',
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
                    // Le contour est porté par les deux états — transparent sur
                    // le courant — sinon les pastilles ne font pas la même
                    // hauteur et la ligne se décale de 2 px.
                    'press flex items-center gap-2 rounded-full border text-[13px] font-bold',
                    'px-3.5 py-2' => $variant === 'bar',
                    'px-3 py-2' => $variant === 'column',
                    'border-transparent bg-(--acbg) text-(--ac)' => $isCurrent,
                    'border-(--bd) bg-(--surf) text-(--mut) hover:border-(--bd2) hover:text-(--tx)' => ! $isCurrent,
                ])
            >
                {{ $tab['label'] }}
                <x-nav-signal :key="$tab['signal']" :on-accent="$isCurrent" />
            </a>
        @endforeach
    </nav>
@endif
