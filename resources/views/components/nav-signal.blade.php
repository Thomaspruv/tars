@props([
    'key' => null,
    'as' => null,
])

@php
    // Le vert est le compte de l'agent, l'abricot le mien : une décision à
    // trancher n'attend personne d'autre que moi. `as="dot"` tait le chiffre
    // sans taire le signal — c'est ce que portent les espaces.
    $signal = \App\Support\Navigation\NavSignals::get($key);
    $display = $signal === null ? null : ($as ?? $signal['display']);
@endphp

@if ($signal && $display === 'count')
    <span class="font-display rounded-full px-[9px] py-0.5 text-[12px] font-bold {{ $signal['tone'] === 'mine' ? 'bg-(--acbg) text-(--ac)' : 'bg-(--aibg) text-(--ai)' }}">{{ $signal['count'] }}</span>
    <span class="sr-only">{{ $signal['sr'] }}</span>
@elseif ($signal && $display === 'dot')
    <span class="size-[8px] rounded-full {{ $signal['tone'] === 'mine' ? 'bg-(--ac)' : 'bg-(--ai)' }}" aria-hidden="true"></span>
    <span class="sr-only">{{ $signal['sr'] }}</span>
@endif
