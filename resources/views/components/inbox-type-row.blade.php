@props([
    'item',
    // Le type qu'Hermès propose : il prend la teinte de l'agent dans la
    // rangée, les autres restent neutres. Rien n'est préselectionné.
    'suggested' => null,
])

@php
    $types = [
        'task' => ['Tâche', 'convertToTask'],
        'event' => ['Événement', 'convertToEvent'],
        'goal' => ['Objectif', 'convertToGoal'],
        'note' => ['Note', 'convertToNote'],
    ];

    $suggestedValue = $suggested instanceof \App\Enums\InboxConvertedType ? $suggested->value : $suggested;
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-2']) }}>
    @foreach ($types as $value => [$label, $action])
        <x-btn
            size="xs"
            variant="secondary"
            wire:click="{{ $action }}({{ $item->id }})"
            @class(['!bg-(--aibg) !text-(--ai) !font-semibold' => $value === $suggestedValue])
        >{{ $label }}</x-btn>
    @endforeach

    <x-btn variant="ghost" size="xs" class="ml-auto" wire:click="discard({{ $item->id }})">Supprimer</x-btn>
</div>
