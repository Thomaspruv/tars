@props(['decision', 'index', 'total'])

@php
    $responseLabels = ['oui' => 'Oui', 'non' => 'Non', 'plus_tard' => 'Reporté'];
@endphp

<div
    {{ $attributes->merge(['class' => 'rounded-[10px] border-l-2 bg-(--surf) p-4 '.($decision['response'] ? 'border-(--ok) opacity-60' : 'border-(--ac)')]) }}
>
    <p class="font-mono text-[10.5px] font-semibold tracking-[.08em] text-(--ac) uppercase">Décision {{ $index + 1 }}/{{ $total }}</p>
    <p class="mt-1 text-[14.5px] font-medium leading-[1.45] text-(--tx)">{{ $decision['question'] }}</p>

    @if ($decision['response'])
        <p class="mt-2 text-xs text-(--ok)">✓ {{ $responseLabels[$decision['response']] ?? $decision['response'] }} — enregistré au journal de décisions</p>
    @else
        <div class="mt-3 flex gap-2">
            <x-btn variant="primary" size="sm" wire:click="answerDecision({{ $index }}, 'oui')">Oui</x-btn>
            <x-btn variant="secondary" size="sm" wire:click="answerDecision({{ $index }}, 'non')">Non</x-btn>
            <x-btn variant="ghost" size="sm" wire:click="answerDecision({{ $index }}, 'plus_tard')">Plus tard</x-btn>
        </div>
    @endif
</div>
