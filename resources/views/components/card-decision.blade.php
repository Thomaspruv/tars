@props(['decision', 'index', 'total'])

@php
    $responseLabels = ['oui' => 'Oui', 'non' => 'Non', 'plus_tard' => 'Reporté'];
    $context = collect([$decision['goal'] ?? null, $decision['entity'] ?? null])->filter()->implode(' · ');
@endphp

{{-- Les décisions cassent la lecture en cartes larges. Elles sont vertes comme
     tout le reste de la revue : c'est l'agent qui pose la question, mais la
     réponse, elle, est tracée dans le journal des décisions. --}}
<x-card
    tone="agent"
    padding="22"
    {{ $attributes->merge(['class' => $decision['response'] ? 'opacity-60' : '']) }}
>
    <p class="mb-2.5 text-[11px] tracking-[.12em] text-(--ai) uppercase">
        Décision {{ $index + 1 }} sur {{ $total }}@if ($context) · {{ $context }} @endif
    </p>

    <p class="font-display mb-2 text-[20px] leading-[1.35] font-semibold text-(--tx)">{{ $decision['question'] }}</p>

    @if (! empty($decision['rationale']))
        <p class="mb-[18px] text-[15px] leading-[1.7] text-(--mut)">{{ $decision['rationale'] }}</p>
    @endif

    @if ($decision['response'])
        <p class="text-[14px] text-(--ai)">
            ✓ {{ $responseLabels[$decision['response']] ?? $decision['response'] }} — tracé au journal des décisions
        </p>
    @else
        <div class="flex flex-wrap gap-2.5">
            <x-btn variant="agent" size="md" wire:click="answerDecision({{ $index }}, 'oui')">Oui</x-btn>
            <x-btn variant="secondary" size="md" wire:click="answerDecision({{ $index }}, 'non')">Non</x-btn>
            <x-btn variant="ghost" size="md" wire:click="answerDecision({{ $index }}, 'plus_tard')">Plus tard</x-btn>
        </div>
    @endif
</x-card>
