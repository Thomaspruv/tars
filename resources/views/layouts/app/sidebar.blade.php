<?php

use App\Enums\BrainSuggestionStatus;
use App\Models\BrainSuggestion;
use App\Models\InboxItem;
use App\Models\QuestionnaireRun;

$inboxPendingCount = InboxItem::whereNull('processed_at')->count();
$curatorPendingCount = BrainSuggestion::where('status', BrainSuggestionStatus::Pending->value)->count();
$bilanPendingCount = QuestionnaireRun::where('status', 'pending')->count();

$theme = request()->cookie('la-theme') === 'light' ? 'light' : 'dark';

$navItems = [
    ['route' => 'today', 'label' => 'Aujourd\'hui', 'icon' => 'home'],
    ['route' => 'inbox.index', 'label' => 'Inbox', 'icon' => 'inbox'],
    ['route' => 'goals.index', 'label' => 'Objectifs', 'icon' => 'flag', 'alsoRouteIs' => 'goals.show'],
    ['route' => 'entities.index', 'label' => 'Entités', 'icon' => 'building-office-2', 'alsoRouteIs' => 'entities.show'],
    ['route' => 'lists.index', 'label' => 'Listes', 'icon' => 'list-bullet'],
    ['route' => 'brain.index', 'label' => 'Cerveau', 'icon' => 'document-text'],
    ['route' => 'tasks.index', 'label' => 'Tâches', 'icon' => 'check-circle'],
    ['route' => 'calendar.index', 'label' => 'Calendrier', 'icon' => 'calendar'],
    ['route' => 'review.index', 'label' => 'Revue', 'icon' => 'clipboard-document-check'],
    ['route' => 'bilan.index', 'label' => 'Bilan de vie', 'icon' => 'chart-bar'],
];
?>
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ $theme }}" class="{{ $theme === 'dark' ? 'dark' : '' }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-(--bg) text-(--tx) antialiased">
        <flux:sidebar sticky collapsible="mobile" class="w-[216px] border-e border-(--bd) bg-(--surf)">
            <flux:sidebar.header>
                <a href="{{ route('today') }}" wire:navigate class="flex items-center gap-2.5">
                    <x-app-logo-icon class="size-8" />
                    <span class="font-mono font-semibold tracking-tight text-(--tx)">TARS</span>
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <nav class="flex flex-col gap-1 px-2">
                @foreach ($navItems as $item)
                    @php
                        $isCurrent = request()->routeIs($item['route']) || (isset($item['alsoRouteIs']) && request()->routeIs($item['alsoRouteIs']));
                    @endphp
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        class="flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-sm transition-colors {{ $isCurrent ? 'bg-(--acbg) font-semibold text-(--ac)' : 'text-(--mut) hover:bg-(--surf2) hover:text-(--tx)' }}"
                    >
                        <flux:icon :name="$item['icon']" class="size-[18px] shrink-0" />
                        <span class="flex-1">{{ $item['label'] }}</span>
                        @if ($item['route'] === 'inbox.index' && $inboxPendingCount > 0)
                            <span class="rounded-full bg-(--warnbg) px-1.5 py-0.5 font-mono text-[10.5px] font-semibold text-(--warn)">{{ $inboxPendingCount }}</span>
                        @endif
                        @if ($item['route'] === 'brain.index' && $curatorPendingCount > 0)
                            <span class="rounded-full bg-(--aibg) px-1.5 py-0.5 font-mono text-[10.5px] font-semibold text-(--ai)">{{ $curatorPendingCount }}</span>
                        @endif
                        @if ($item['route'] === 'bilan.index' && $bilanPendingCount > 0)
                            <span class="animate-pulse-soft rounded-full bg-(--acbg) px-1.5 py-0.5 font-mono text-[10.5px] font-semibold text-(--ac)">{{ $bilanPendingCount }}</span>
                        @endif
                    </a>
                @endforeach
            </nav>

            <flux:spacer />

            <nav class="flex flex-col gap-1 px-2 pb-2">
                <a
                    href="{{ route('agents.index') }}"
                    wire:navigate
                    class="flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-sm transition-colors {{ request()->routeIs('agents.index') ? 'bg-(--aibg) font-semibold text-(--ai)' : 'text-(--mut) hover:bg-(--surf2) hover:text-(--ai)' }}"
                >
                    <flux:icon name="cpu-chip" class="size-[18px] shrink-0" />
                    <span class="flex-1">Agents</span>
                </a>
                <a
                    href="{{ route('settings.index') }}"
                    wire:navigate
                    class="flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-sm transition-colors {{ request()->routeIs('settings.index') ? 'bg-(--acbg) font-semibold text-(--ac)' : 'text-(--mut) hover:bg-(--surf2) hover:text-(--tx)' }}"
                >
                    <flux:icon name="cog-6-tooth" class="size-[18px] shrink-0" />
                    <span class="flex-1">Réglages</span>
                </a>

                <button
                    type="button"
                    x-data
                    x-on:click="
                        let next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                        document.documentElement.dataset.theme = next;
                        document.documentElement.classList.toggle('dark', next === 'dark');
                        document.cookie = 'la-theme=' + next + '; path=/; max-age=31536000; SameSite=Lax';
                    "
                    class="flex items-center gap-2.5 rounded-[10px] px-3 py-2 text-sm text-(--mut) transition-colors hover:bg-(--surf2) hover:text-(--tx)"
                >
                    <flux:icon name="sun" data-theme-icon="sun" class="size-[18px] shrink-0" />
                    <flux:icon name="moon" data-theme-icon="moon" class="size-[18px] shrink-0" />
                    <span class="flex-1 text-left">Thème</span>
                </button>
            </nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <flux:header class="border-b border-(--bd) bg-(--surf) lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
