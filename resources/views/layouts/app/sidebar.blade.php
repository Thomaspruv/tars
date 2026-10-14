<?php

use App\Enums\BrainSuggestionStatus;
use App\Models\BrainSuggestion;
use App\Enums\OpenDecisionStatus;
use App\Models\InboxItem;
use App\Models\OpenDecision;
use App\Models\QuestionnaireRun;
use App\Models\Review;

$inboxPendingCount = InboxItem::whereNull('processed_at')->count();
$curatorPendingCount = BrainSuggestion::where('status', BrainSuggestionStatus::Pending->value)->count();
$bilanPendingCount = QuestionnaireRun::where('status', 'pending')->count();
$reviewIsPending = Review::whereNull('completed_at')->exists();
$openDecisionCount = OpenDecision::where('status', OpenDecisionStatus::Open->value)->count();

// Trois groupes nommés : ce que je fais maintenant, ce que je suis dans le
// temps, ce que je prends le temps de penser. Pas d'icônes — à quatorze
// destinations nommées en français, le texte seul se scanne plus vite qu'un
// pictogramme à décoder.
$navGroups = [
    [
        'kicker' => 'Faire',
        'items' => [
            ['route' => 'today', 'label' => 'Aujourd\'hui'],
            ['route' => 'inbox.index', 'label' => 'Inbox', 'count' => $inboxPendingCount, 'tone' => 'agent'],
            ['route' => 'tasks.index', 'label' => 'Tâches'],
            ['route' => 'calendar.index', 'label' => 'Calendrier'],
        ],
    ],
    [
        'kicker' => 'Suivre',
        'items' => [
            ['route' => 'goals.index', 'label' => 'Objectifs', 'alsoRouteIs' => 'goals.show'],
            ['route' => 'entities.index', 'label' => 'Entités', 'alsoRouteIs' => 'entities.show'],
            ['route' => 'lists.index', 'label' => 'Listes'],
        ],
    ],
    [
        'kicker' => 'Réfléchir',
        'items' => [
            ['route' => 'decisions.index', 'label' => 'Décisions', 'alsoRouteIs' => 'decisions.show', 'count' => $openDecisionCount, 'tone' => 'mine'],
            ['route' => 'review.index', 'label' => 'Revue', 'dot' => $reviewIsPending],
            ['route' => 'brain.index', 'label' => 'Cerveau', 'count' => $curatorPendingCount, 'tone' => 'agent'],
            ['route' => 'bilan.index', 'label' => 'Bilan de vie', 'count' => $bilanPendingCount, 'tone' => 'agent'],
        ],
    ],
];

?>

<aside class="fixed inset-y-0 left-0 z-20 hidden w-[232px] flex-col px-4 py-[22px] lg:flex">
    <a href="{{ route('today') }}" wire:navigate class="mb-[22px] flex items-center gap-[11px] px-2.5">
        <x-app-logo-icon class="size-[22px]" />
        <span class="font-display text-[19px] font-bold tracking-[-.01em] text-(--tx)">TARS</span>
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

    @foreach ($navGroups as $groupIndex => $group)
        <p class="mt-[18px] mb-[7px] px-4 text-[11px] tracking-[.12em] text-(--mut) uppercase {{ $groupIndex === 0 ? '!mt-0' : '' }}">{{ $group['kicker'] }}</p>

        <nav class="flex flex-col gap-[3px]">
            @foreach ($group['items'] as $item)
                @php
                    $isCurrent = request()->routeIs($item['route'])
                        || (isset($item['alsoRouteIs']) && request()->routeIs($item['alsoRouteIs']));
                @endphp
                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    @class([
                        'press flex items-center rounded-full px-4 py-2.5 text-[14px]',
                        'bg-(--surf2) font-semibold text-(--tx)' => $isCurrent,
                        'text-(--tx) hover:bg-(--surf)' => ! $isCurrent,
                    ])
                >
                    <span class="flex-1">{{ $item['label'] }}</span>

                    @if (($item['count'] ?? 0) > 0)
                        {{-- Le vert est le compte de l'agent, l'abricot le mien : une
                             décision à trancher n'attend personne d'autre que moi. --}}
                        <span class="font-display rounded-full px-[9px] py-0.5 text-[12px] font-semibold {{ ($item['tone'] ?? 'agent') === 'mine' ? 'bg-(--acbg) text-(--ac)' : 'bg-(--aibg) text-(--ai)' }}">{{ $item['count'] }}</span>
                    @elseif ($item['dot'] ?? false)
                        <span class="size-[8px] rounded-full bg-(--ai)" aria-hidden="true"></span>
                        <span class="sr-only">une revue vous attend</span>
                    @endif
                </a>
            @endforeach
        </nav>
    @endforeach

    <div class="mt-auto">
        <nav class="mb-3 flex flex-col gap-[3px]">
            <a
                href="{{ route('agents.index') }}"
                wire:navigate
                class="press rounded-full px-4 py-[9px] text-[13px] {{ request()->routeIs('agents.index') ? 'bg-(--surf2) font-semibold text-(--tx)' : 'text-(--mut) hover:text-(--tx)' }}"
            >Agents</a>
            <a
                href="{{ route('settings.index') }}"
                wire:navigate
                class="press rounded-full px-4 py-[9px] text-[13px] {{ request()->routeIs('settings.index') ? 'bg-(--surf2) font-semibold text-(--tx)' : 'text-(--mut) hover:text-(--tx)' }}"
            >Réglages</a>
            <x-theme-toggle class="press rounded-full px-4 py-[9px] text-left text-[13px] text-(--mut) hover:text-(--tx)" />
        </nav>

        <x-desktop-user-menu />
    </div>
</aside>
