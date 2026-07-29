@props(['entity', 'openTasksCount' => null, 'nextDueDate' => null, 'linkedGoal' => null])

@php
    $type = $entity->type instanceof \App\Enums\EntityType ? $entity->type->value : $entity->type;
    $emoji = ['company' => '🏢', 'property' => '🏠'][$type] ?? null;
    $typeLabels = ['company' => 'Entreprise', 'property' => 'Bien', 'vehicle' => 'Véhicule', 'other' => 'Autre'];

    $isDueSoon = $nextDueDate && ! $nextDueDate->isPast() && $nextDueDate->diffInDays(now()) <= 14;
    $isOverdue = $nextDueDate && $nextDueDate->isPast();
@endphp

<a
    href="{{ route('entities.show', $entity) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'block rounded-[12px] border border-(--bd) bg-(--surf) p-[18px] shadow-(--card-shadow) transition-colors duration-150 ease-out hover:border-(--bd2)']) }}
>
    <div class="flex items-center gap-3">
        <span class="flex size-[34px] shrink-0 items-center justify-center rounded-[9px] bg-(--surf2) text-lg">
            {{ $emoji }}
        </span>
        <div>
            <p class="text-[14.5px] font-medium text-(--tx)">{{ $entity->name }}</p>
            <p class="text-[11.5px] text-(--mut)">{{ $typeLabels[$type] ?? ucfirst($type) }}</p>
        </div>
    </div>

    <dl class="mt-3 space-y-2 border-t border-(--bd) pt-3 text-[12.5px]">
        <div class="flex items-center justify-between">
            <dt class="text-(--mut)">Tâches ouvertes</dt>
            <dd class="font-mono text-(--tx)">{{ $openTasksCount ?? 0 }}</dd>
        </div>
        <div class="flex items-center justify-between">
            <dt class="text-(--mut)">Prochaine échéance</dt>
            <dd class="font-mono {{ $isOverdue ? 'text-(--dgr)' : ($isDueSoon ? 'text-(--warn)' : 'text-(--mut)') }}">
                {{ $nextDueDate?->translatedFormat('d M') ?? '—' }}
            </dd>
        </div>
        <div class="flex items-center justify-between">
            <dt class="text-(--mut)">Objectif lié</dt>
            <dd>
                @if ($linkedGoal)
                    <x-badge type="goal" :tag="$linkedGoal->tag" />
                @else
                    <span class="text-(--mut)">—</span>
                @endif
            </dd>
        </div>
    </dl>
</a>
