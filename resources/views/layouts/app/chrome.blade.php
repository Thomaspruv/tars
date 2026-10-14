<?php

use App\Models\InboxItem;

$inboxPendingCount = InboxItem::whereNull('processed_at')->count();

$theme = request()->cookie('la-theme') === 'light' ? 'light' : 'dark';

// La barre du bas mobile ne porte que les cinq écrans de travail — les neuf
// autres vivent derrière « Plus ». Ce n'est pas la parité avec le desktop,
// c'est le confort du pouce.
$mobileTabs = [
    ['route' => 'today', 'label' => 'Aujourd\'hui'],
    ['route' => 'inbox.index', 'label' => 'Inbox'],
    ['route' => 'tasks.index', 'label' => 'Tâches'],
    ['route' => 'lists.index', 'label' => 'Listes'],
];

$moreLinks = [
    ['route' => 'calendar.index', 'label' => 'Calendrier'],
    ['route' => 'goals.index', 'label' => 'Objectifs'],
    ['route' => 'entities.index', 'label' => 'Entités'],
    ['route' => 'brain.index', 'label' => 'Cerveau'],
    ['route' => 'decisions.index', 'label' => 'Décisions'],
    ['route' => 'review.index', 'label' => 'Revue'],
    ['route' => 'bilan.index', 'label' => 'Bilan de vie'],
    ['route' => 'agents.index', 'label' => 'Agents'],
    ['route' => 'settings.index', 'label' => 'Réglages'],
];
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}" class="{{ $theme === 'dark' ? 'dark' : '' }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-(--bg) font-sans text-(--tx) antialiased">
        {{ $slot }}

        {{-- Chrome mobile : cinq onglets, plus le bouton de capture au pouce.
             Toutes les cibles font au moins 44px. --}}
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
                @foreach ($mobileTabs as $tab)
                    @php $isCurrent = request()->routeIs($tab['route']); @endphp
                    <a
                        href="{{ route($tab['route']) }}"
                        wire:navigate
                        @class([
                            'press flex-1 py-2 text-center text-[11px]',
                            'text-(--ac)' => $isCurrent,
                            'text-(--mut)' => ! $isCurrent,
                        ])
                    >
                        <span class="mx-auto mb-2 block h-[3px] w-[20px] rounded-full transition-colors duration-(--dur-swap) ease-(--ease) {{ $isCurrent ? 'bg-(--ac)' : 'bg-transparent' }}"></span>
                        {{ $tab['label'] }}
                        @if ($tab['route'] === 'inbox.index' && $inboxPendingCount > 0)
                            <span class="sr-only">, {{ $inboxPendingCount }} en attente</span>
                        @endif
                    </a>
                @endforeach

                <button type="button" x-on:click="more = true" class="press flex-1 py-2 text-center text-[11px] text-(--mut)">
                    <span class="mx-auto mb-2 block h-[3px] w-[20px] rounded-full bg-transparent"></span>
                    Plus
                </button>
            </nav>

            <div x-show="more" x-cloak class="fixed inset-0 z-40" x-on:keydown.escape.window="more = false">
                <div class="animate-veil-in absolute inset-0 bg-black/55" x-on:click="more = false"></div>
                <div class="animate-sheet-up absolute inset-x-0 bottom-0 rounded-t-[28px] bg-(--surf) px-5 pt-5 pb-8">
                    <div class="mx-auto mb-5 h-1 w-10 rounded-full bg-(--bd2)"></div>
                    <div class="flex flex-col gap-1">
                        @foreach ($moreLinks as $link)
                            <a
                                href="{{ route($link['route']) }}"
                                wire:navigate
                                x-on:click="more = false"
                                class="press rounded-full px-4 py-3 text-[15px] {{ request()->routeIs($link['route']) ? 'bg-(--surf2) font-semibold' : 'text-(--tx)' }}"
                            >{{ $link['label'] }}</a>
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
