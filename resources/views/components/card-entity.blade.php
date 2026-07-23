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
    {{ $attributes->merge(['class' => 'block rounded-[14px] border border-(--bd) bg-(--surf) p-5 transition-colors hover:border-(--bd2)']) }}
>
    <div class="flex items-center gap-3">
        <span class="flex size-[38px] shrink-0 items-center justify-center rounded-[10px] bg-(--surf2) text-lg">
            @if ($emoji)
                {{ $emoji }}
            @else
                <flux:icon :name="$type === 'vehicle' ? 'truck' : 'cube'" class="size-5 text-(--mut)" />
            @endif
        </span>
        <div>
            <p class="text-[15px] font-semibold text-(--tx)">{{ $entity->name }}</p>
            <p class="text-xs text-(--mut)">{{ $typeLabels[$type] ?? ucfirst($type) }}</p>
        </div>
    </div>

    <dl class="mt-4 space-y-2 text-xs">
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
                    <x-badge-goal :tag="$linkedGoal->tag" />
                @else
                    <span class="text-(--mut)">—</span>
                @endif
            </dd>
        </div>
    </dl>
</a>
