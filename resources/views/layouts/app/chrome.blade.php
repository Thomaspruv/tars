<?php

use App\Support\Navigation\Spaces;

$theme = request()->cookie('la-theme') === 'light' ? 'light' : 'dark';

// La barre du bas mobile porte cinq des sept portes, dans l'ordre du doc :
// Jour, À trier, Planning, Mémoire, Hermès. Objectifs et Recul passent derrière
// « Plus » — on les consulte, on ne les manipule pas sur téléphone : un bilan
// trimestriel ne se lit pas debout dans le métro, et poser un objectif encore
// moins. Les labels courts servent ici, l'écran fait 390.
$behind = ['objectifs', 'recul'];
$mobileSpaces = array_values(array_filter(Spaces::all(), fn (array $space): bool => ! in_array($space['key'], $behind, true)));

// La feuille « Plus » reprend les trois groupes de l'écran M6 : ce qu'on
// consulte, ce qu'on relit, et moi.
$moreGroups = [
    'Objectifs' => [['route' => 'goals.index', 'label' => 'Objectifs'], ['route' => 'decisions.index', 'label' => 'Décisions']],
    'Recul' => [['route' => 'review.index', 'label' => 'Revue'], ['route' => 'bilan.index', 'label' => 'Bilan de vie']],
    'Toi' => [['route' => 'profile.edit', 'label' => 'Mon compte'], ['route' => 'settings.index', 'label' => 'Réglages']],
];
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}" class="{{ $theme === 'dark' ? 'dark' : '' }}">
    <head>
        @include('partials.head')
    </head>
    {{-- Pas d'`antialiased` : sur macOS, `-webkit-font-smoothing: antialiased`
         amincit visiblement les traits, et la maquette ne le déclare nulle part.
         C'est ce qui faisait paraître la police moins épaisse que le design à
         graisses pourtant identiques. --}}
    <body class="min-h-screen bg-(--bg) font-sans text-(--tx)">
        {{ $slot }}

        {{-- Chrome mobile : quatre espaces et « Plus », plus le bouton de
             capture au pouce. Toutes les cibles font au moins 44px. --}}
        <div x-data="{ more: false }" class="lg:hidden">
            <div class="pointer-events-none fixed right-5 bottom-[92px] z-30">
                <button
                    type="button"
                    x-on:click="window.dispatchEvent(new CustomEvent('open-quick-add'))"
                    aria-label="Poser quelque chose"
                    class="pointer-events-auto flex size-[56px] items-center justify-center rounded-full bg-(--ac) text-[26px] font-bold text-(--acT) shadow-[0_3px_0_var(--acsh)] transition-[box-shadow,translate] duration-(--dur-tap) ease-(--ease) active:translate-y-[3px] active:shadow-none"
                >
                    <span aria-hidden="true">+</span>
                </button>
            </div>

            <nav class="fixed inset-x-0 bottom-0 z-30 flex border-t border-(--bd) bg-(--sunk) px-2 pt-2.5 pb-[22px]">
                @foreach ($mobileSpaces as $space)
                    @php $isCurrent = request()->routeIs(...Spaces::routesOf($space)); @endphp
                    <a
                        href="{{ route($space['tabs'][0]['route']) }}"
                        wire:navigate
                        @if ($isCurrent) aria-current="page" @endif
                        @class([
                            'press flex-1 py-2 text-center text-[11px] font-bold',
                            'text-(--ac)' => $isCurrent,
                            'text-(--mut)' => ! $isCurrent,
                        ])
                    >
                        <span class="mx-auto mb-2 block h-[3px] w-[20px] rounded-full transition-colors duration-(--dur-swap) ease-(--ease) {{ $isCurrent ? 'bg-(--ac)' : 'bg-transparent' }}"></span>
                        {{ $space['short'] }}
                        @php $signal = \App\Support\Navigation\NavSignals::get($space['signal']['key'] ?? null); @endphp
                        @if ($signal)<span class="sr-only">, {{ $signal['count'] }} {{ $signal['sr'] }}</span>@endif
                    </a>
                @endforeach

                <button type="button" x-on:click="more = true" class="press flex-1 py-2 text-center text-[11px] font-bold text-(--mut)">
                    <span class="mx-auto mb-2 block h-[3px] w-[20px] rounded-full bg-transparent"></span>
                    Plus
                </button>
            </nav>

            <div x-show="more" x-cloak class="fixed inset-0 z-40" x-on:keydown.escape.window="more = false">
                <div class="animate-veil-in absolute inset-0 bg-black/55" x-on:click="more = false"></div>
                <div class="animate-sheet-up absolute inset-x-0 bottom-0 rounded-t-[28px] bg-(--surf) px-5 pt-5 pb-8">
                    <div class="mx-auto mb-5 h-1 w-10 rounded-full bg-(--bd2)"></div>
                    <div class="flex flex-col gap-4">
                        @foreach ($moreGroups as $heading => $links)
                            <div class="flex flex-col gap-1">
                                <p class="px-4 pb-1 text-[11px] text-(--mut) kicker">{{ $heading }}</p>
                                @foreach ($links as $link)
                                    <a
                                        href="{{ route($link['route']) }}"
                                        wire:navigate
                                        x-on:click="more = false"
                                        class="press rounded-full px-4 py-3 text-[15px] {{ request()->routeIs($link['route']) ? 'bg-(--surf2) font-semibold' : 'text-(--tx)' }}"
                                    >{{ $link['label'] }}</a>
                                @endforeach
                            </div>
                        @endforeach

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="press w-full rounded-full px-4 py-3 text-left text-[15px] text-(--mut)">Se déconnecter</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <livewire:quick-add />

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
