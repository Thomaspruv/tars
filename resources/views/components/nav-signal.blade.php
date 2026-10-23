@props([
    'key' => null,
    'as' => null,
    // Posé sur un fond déjà abricot (l'onglet courant) : un chiffre « mien »
    // y perdrait sa pastille, puisque `--acbg` serait sur `--acbg`. Il passe
    // donc en plein abricot et reste une pastille.
    'onAccent' => false,
])

@php
    // Le vert est le compte de l'agent, l'abricot le mien : une décision à
    // trancher n'attend personne d'autre que moi. `as="dot"` tait le chiffre
    // sans taire le signal — c'est ce que portent les espaces.
    $signal = \App\Support\Navigation\NavSignals::get($key);
    $display = $signal === null ? null : ($as ?? $signal['display']);
    $mine = $signal !== null && $signal['tone'] === 'mine';
    $countTone = match (true) {
        $mine && $onAccent => 'bg-(--ac) text-(--acT)',
        $mine => 'bg-(--acbg) text-(--ac)',
        default => 'bg-(--aibg) text-(--ai)',
    };
@endphp

@if ($signal && $display === 'count')
    <span class="font-display rounded-full px-[9px] py-0.5 text-[12px] font-bold {{ $countTone }}">{{ $signal['count'] }}</span>
    <span class="sr-only">{{ $signal['sr'] }}</span>
@elseif ($signal && $display === 'dot')
    <span class="size-[8px] rounded-full {{ $signal['tone'] === 'mine' ? 'bg-(--ac)' : 'bg-(--ai)' }}" aria-hidden="true"></span>
    <span class="sr-only">{{ $signal['sr'] }}</span>
@endif
