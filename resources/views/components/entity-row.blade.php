@props(['entity', 'openTasksCount' => 0, 'nextDueDate' => null, 'linkedGoal' => null, 'urgent' => false])

@php
    $type = $entity->type instanceof \App\Enums\EntityType ? $entity->type->value : $entity->type;
    $typeLabels = ['company' => 'Société', 'property' => 'Bien', 'vehicle' => 'Véhicule', 'other' => 'Autre'];

    // Deux initiales, jamais un emoji : à quatorze entités, l'initiale se
    // reconnaît plus vite qu'un pictogramme générique.
    $initials = collect(preg_split('/[\s\-]+/', (string) $entity->name))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');

    $isOverdue = $nextDueDate && $nextDueDate->isPast();
@endphp

<a
    href="{{ route('entities.show', $entity) }}"
    wire:navigate
    {{ $attributes->merge(['class' => 'press-row flex flex-wrap items-center gap-x-[18px] gap-y-2 px-[22px] py-4 hover:bg-(--surf2)']) }}
>
    <span
        @class([
            'font-display inline-flex size-[34px] shrink-0 items-center justify-center rounded-[10px] text-[14px] font-bold',
            'bg-(--acbg) text-(--ac)' => $urgent,
            'bg-(--surf2) text-(--mut)' => ! $urgent,
        ])
        aria-hidden="true"
    >{{ $initials }}</span>

    <div class="w-[240px] min-w-0">
        <p class="truncate text-[15px] {{ $urgent ? 'font-semibold' : '' }} text-(--tx)">{{ $entity->name }}</p>
        <p class="text-[12px] text-(--mut)">{{ $typeLabels[$type] ?? ucfirst((string) $type) }}</p>
    </div>

    {{-- Le signal principal d'une entité est son échéance qui approche : elle
         se maintient, elle ne progresse pas — donc aucune barre nulle part. --}}
    <div class="w-[190px] min-w-0">
        @if ($nextDueDate)
            <p class="truncate text-[14px] {{ $isOverdue ? 'text-(--dgr)' : ($urgent ? 'text-(--warn)' : 'text-(--mut)') }}">
                {{ $nextDueDate->translatedFormat('j F') }}
            </p>
            <p class="text-[12px] text-(--mut)">
                {{ $isOverdue ? 'dépassée' : 'dans '.(int) ceil(today()->diffInWeeks($nextDueDate)).' semaines' }}
            </p>
        @else
            <p class="text-[13px] text-(--mut)">aucune échéance</p>
        @endif
    </div>

    <div class="w-[140px] text-[13px] text-(--mut)">
        @if ($openTasksCount > 0)
            {{ $openTasksCount }} {{ Str::plural('tâche', $openTasksCount) }} {{ Str::plural('ouverte', $openTasksCount) }}
        @else
            aucune tâche
        @endif
    </div>

    @if ($linkedGoal)
        <span class="ml-auto flex shrink-0 items-center gap-2 text-[13px] text-(--mut)">
            <span class="size-[8px] rounded-full" style="background: var(--goal-{{ $linkedGoal->color_slot }})" aria-hidden="true"></span>
            {{ $linkedGoal->title }}
        </span>
    @endif
</a>
