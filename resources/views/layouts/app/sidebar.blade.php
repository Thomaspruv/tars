<?php

use App\Support\Navigation\Spaces;

// Six portes, pas seize destinations : l'app a seize écrans mais six gestes —
// tenir ma journée, trier ce qui est arrivé, placer dans le temps, suivre où je
// vais, retrouver la matière, regarder en arrière. Le détail de chaque porte
// est un onglet du composant `space-tabs`, à son URL d'origine. Pas d'icônes,
// pas de groupes nommés : à six entrées en français, le texte seul suffit.
//
// Hermès ne compte pas parmi les six : il descend en bas, avec les Réglages.
// On y va pour missionner et regarder tourner, pas pour tenir sa journée.
$doors = Spaces::doors();
$aside = Spaces::aside();

?>

<aside class="sidebar sticky top-0 z-20 hidden h-screen shrink-0 flex-col px-4 py-[22px] lg:flex">
    <a href="{{ route('today') }}" wire:navigate class="mb-[22px] flex items-center gap-[11px] px-2.5">
        <x-app-logo-icon class="size-[22px] shrink-0" />
        <span class="sidebar-brand font-display font-extrabold tracking-[-.01em] text-(--tx)">TARS</span>
    </a>

    {{-- La capture est le seul geste toujours disponible : elle a donc le seul
         bouton abricot permanent de l'app.

         Pas de pastille « ⌘K » dedans. Mesuré : à la largeur plancher du menu
         (232 px, donc toute fenêtre sous ~1390 px) la pilule offre 200 px de
         contenu, quand « + Poser quelque chose ⌘K » en réclame 218 — le
         raccourci débordait de 18 px, et même à 1512 px il venait coller le
         bord arrondi. L'étiquette est le geste, le raccourci n'est qu'un
         indice : il descend dans l'infobulle et dans `aria-keyshortcuts`. --}}
    <button
        type="button"
        x-data
        x-on:click="window.dispatchEvent(new CustomEvent('open-quick-add'))"
        title="Poser quelque chose — ⌘K"
        aria-keyshortcuts="Meta+K"
        class="sidebar-door mb-[22px] flex items-center justify-center gap-2 rounded-full bg-(--ac) px-4 py-3 font-bold whitespace-nowrap text-(--acT) shadow-[0_2px_0_var(--acsh)] transition-[background-color,box-shadow,translate] duration-(--dur-tap) ease-(--ease) hover:bg-(--achov) active:translate-y-[2px] active:shadow-none"
    >
        <span aria-hidden="true" class="text-[17px] leading-none">+</span>
        Poser quelque chose
    </button>

    <nav class="flex flex-col gap-[3px]">
        @foreach ($doors as $space)
            @php $isCurrent = request()->routeIs(...Spaces::routesOf($space)); @endphp
            <a
                href="{{ route($space['tabs'][0]['route']) }}"
                wire:navigate
                @if ($isCurrent) aria-current="page" @endif
                @class([
                    'sidebar-door press flex items-center rounded-full px-4 py-2.5 font-semibold',
                    'bg-(--surf2) text-(--tx)' => $isCurrent,
                    'text-(--tx) hover:bg-(--surf)' => ! $isCurrent,
                ])
            >
                <span class="flex-1">{{ $space['label'] }}</span>
                <x-nav-signal :key="$space['signal']['key'] ?? null" :as="$space['signal']['as'] ?? null" />
            </a>
        @endforeach
    </nav>

    <div class="mt-auto">
        <nav class="mb-3 flex flex-col gap-[3px]">
            @foreach ($aside as $space)
                @php $isCurrent = request()->routeIs(...Spaces::routesOf($space)); @endphp
                <a
                    href="{{ route($space['tabs'][0]['route']) }}"
                    wire:navigate
                    @if ($isCurrent) aria-current="page" @endif
                    @class([
                        'sidebar-aside press flex items-center rounded-full px-4 py-[9px] font-semibold',
                        'bg-(--surf2) text-(--tx)' => $isCurrent,
                        'text-(--mut) hover:text-(--tx)' => ! $isCurrent,
                    ])
                >
                    <span class="flex-1">{{ $space['label'] }}</span>
                    <x-nav-signal :key="$space['signal']['key'] ?? null" :as="$space['signal']['as'] ?? null" />
                </a>
            @endforeach

            <a
                href="{{ route('settings.index') }}"
                wire:navigate
                class="sidebar-aside press rounded-full px-4 py-[9px] font-semibold {{ request()->routeIs('settings.index') ? 'bg-(--surf2) text-(--tx)' : 'text-(--mut) hover:text-(--tx)' }}"
            >Réglages</a>
            <x-theme-toggle class="sidebar-aside press rounded-full px-4 py-[9px] text-left font-semibold text-(--mut) hover:text-(--tx)" />
        </nav>

        <x-desktop-user-menu />
    </div>
</aside>
