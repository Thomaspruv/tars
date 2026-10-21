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

<aside class="sticky top-0 z-20 hidden h-screen w-[232px] shrink-0 flex-col px-4 py-[22px] lg:flex">
    <a href="{{ route('today') }}" wire:navigate class="mb-[22px] flex items-center gap-[11px] px-2.5">
        <x-app-logo-icon class="size-[22px]" />
        <span class="font-display text-[19px] font-extrabold tracking-[-.01em] text-(--tx)">TARS</span>
    </a>

    {{-- La capture est le seul geste toujours disponible : elle a donc le seul
         bouton abricot permanent de l'app. --}}
    <button
        type="button"
        x-data
        x-on:click="window.dispatchEvent(new CustomEvent('open-quick-add'))"
        class="mb-[22px] flex items-center gap-2 rounded-full bg-(--ac) px-4 py-3 text-[14px] font-bold whitespace-nowrap text-(--acT) shadow-[0_2px_0_var(--acsh)] transition-[background-color,box-shadow,translate] duration-(--dur-tap) ease-(--ease) hover:bg-(--achov) active:translate-y-[2px] active:shadow-none"
    >
        <span aria-hidden="true" class="text-[17px] leading-none">+</span>
        Poser quelque chose
        <span class="ml-auto text-[12px] opacity-60">⌘K</span>
    </button>

    <nav class="flex flex-col gap-[3px]">
        @foreach ($doors as $space)
            @php $isCurrent = request()->routeIs(...Spaces::routesOf($space)); @endphp
            <a
                href="{{ route($space['tabs'][0]['route']) }}"
                wire:navigate
                @if ($isCurrent) aria-current="page" @endif
                @class([
                    'press flex items-center rounded-full px-4 py-2.5 text-[14px] font-semibold',
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
                        'press flex items-center rounded-full px-4 py-[9px] text-[13px] font-semibold',
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
                class="press rounded-full px-4 py-[9px] text-[13px] font-semibold {{ request()->routeIs('settings.index') ? 'bg-(--surf2) text-(--tx)' : 'text-(--mut) hover:text-(--tx)' }}"
            >Réglages</a>
            <x-theme-toggle class="press rounded-full px-4 py-[9px] text-left text-[13px] font-semibold text-(--mut) hover:text-(--tx)" />
        </nav>

        <x-desktop-user-menu />
    </div>
</aside>
