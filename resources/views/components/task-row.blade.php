@props([
    'task',
    'nested' => false,
    'density' => 'comfortable', // comfortable (52px) | compact (40px, écran Tâches)
])

@php
    $isDone = $task->status === \App\Enums\TaskStatus::Done;
    $dueDate = $task->due_date ?? $task->scheduled_date;
    $isOverdue = $dueDate && ! $isDone && $dueDate->isPast() && ! $dueDate->isToday();
    $hasSubtasks = ! $nested && $task->subtasks->isNotEmpty();

    $isCompact = $density === 'compact';

    // Le seul écran en mode compact est Tâches — la vue exhaustive. Partout
    // ailleurs la ligne respire, parce qu'on n'y lit que la journée.
    $rowPadding = match (true) {
        $nested => 'py-[9px] pr-2 pl-[42px]',
        $isCompact => 'px-2 py-[10px]',
        default => 'px-[22px] py-[14px]',
    };

    $checkboxSize = $nested ? '16' : ($isCompact ? '18' : '20');
    $checkboxRadius = $nested ? 'rounded-[5px]' : ($isCompact ? 'rounded-[6px]' : 'rounded-[7px]');
    $checkboxBorder = $isOverdue ? 'border-(--dgr)' : ($task->is_delegable ? 'border-(--ai)' : 'border-(--bd3)');

    $titleSize = $nested ? 'text-[13px]' : ($isCompact ? 'text-[14px]' : 'text-[15px]');
@endphp

<div @if (! $nested) x-data="{ open: false }" @endif {{ $attributes }}>
    {{-- Livewire morphe la ligne au lieu de la remplacer : la bascule
         terminé/à faire s'anime donc toute seule dès que l'opacité transite. --}}
    <div class="flex items-center gap-[14px] transition-opacity duration-(--dur-swap) ease-(--ease) {{ $rowPadding }} {{ $isDone ? 'opacity-45' : '' }}">
        @if ($hasSubtasks)
            <button
                type="button"
                @click="open = !open"
                aria-label="Déplier les sous-tâches"
                class="shrink-0 text-[10px] text-(--mut) hover:text-(--tx)"
            >
                <span
                    aria-hidden="true"
                    class="inline-block transition-[rotate] duration-(--dur-swap) ease-(--ease)"
                    :class="open && 'rotate-180'"
                >▾</span>
            </button>
        @endif

        {{-- L'aller-retour Livewire dure ~250 ms. Sans verrou, un deuxième clic
             pendant ce délai renvoie un toggle qui annule le premier : la tâche
             se coche puis se décoche, et on croit avoir cliqué deux fois. Le
             bouton se désactive donc le temps de *sa propre* requête, et se
             creuse pour dire que le clic est bien parti. --}}
        <button
            type="button"
            wire:click="toggleTask({{ $task->id }})"
            wire:target="toggleTask({{ $task->id }})"
            wire:loading.attr="disabled"
            wire:loading.class="scale-90 opacity-60"
            aria-label="{{ $isDone ? 'Marquer non terminée' : 'Marquer terminée' }}"
            @class([
                'relative flex shrink-0 items-center justify-center border-2 transition-[background-color,border-color,scale] duration-(--dur-swap) ease-(--ease) active:scale-[.88] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ac)',
                $checkboxRadius,
                'size-[16px]' => $checkboxSize === '16',
                'size-[18px]' => $checkboxSize === '18',
                'size-[20px]' => $checkboxSize === '20',
                'border-(--ai) bg-(--ai)' => $isDone,
                $checkboxBorder => ! $isDone,
            ])
        >
            @if ($isDone)
                <span aria-hidden="true" class="animate-tick-in text-[11px] leading-none text-(--aiT)">✓</span>
            @endif
        </button>

        <span class="min-w-0 flex-1 truncate transition-colors duration-(--dur-swap) ease-(--ease) {{ $titleSize }} {{ $isDone ? 'text-(--mut) line-through' : ($nested ? 'text-(--mut)' : 'text-(--tx)') }}">
            {{ $task->title }}
        </span>

        @if ($hasSubtasks)
            <span class="shrink-0 text-[12px] text-(--mut)">{{ $task->subtasks->where('status', \App\Enums\TaskStatus::Done)->count() }}/{{ $task->subtasks->count() }}</span>
        @endif

        {{-- Le retard se lit au bord de la case, en brique. Pas de pilule
             abricot : l'abricot est *mon* action, jamais une alarme — et une
             tâche en retard de trois jours n'est pas une urgence. --}}
        @if ($dueDate)
            <time
                class="ml-auto shrink-0 text-[12px] text-(--mut)"
                datetime="{{ $dueDate->toDateString() }}"
            >
                @if ($isDone && $task->completed_at)
                    fait à {{ $task->completed_at->format('H:i') }}
                @elseif ($dueDate->isToday())
                    aujourd'hui
                @else
                    {{ $dueDate->translatedFormat('j M') }}
                @endif
            </time>
        @endif

        @unless ($nested)
            @if (! $isCompact && ! $isOverdue && $task->priority?->value === 'p1')
                <span class="shrink-0 rounded-full bg-(--surf2) px-[11px] py-1 text-[12px] text-(--warn)">priorité</span>
            @endif

            @if ($isCompact)
                <x-badge type="priority" :value="$task->priority" />
            @endif

            @if ($task->is_delegable)
                <x-badge type="delegable" class="shrink-0" />
            @endif

            {{-- L'objectif ferme la ligne, aligné à droite sur une colonne
                 fixe : c'est ce qui rend la liste scannable en diagonale. --}}
            <span class="flex shrink-0 justify-end {{ $isCompact ? 'w-[150px]' : 'w-[110px]' }}">
                @if ($task->goal)
                    <x-badge type="goal" :goal="$task->goal" class="truncate" />
                @elseif ($task->entity)
                    <span class="truncate text-[13px] text-(--mut)">{{ $task->entity->name }}</span>
                @endif
            </span>
        @endunless

        @isset($actions)
            <div class="shrink-0">{{ $actions }}</div>
        @endisset
    </div>

    @unless ($nested)
        <div
            x-show="open"
            x-cloak
            x-transition:enter="transition-[opacity,translate] duration-(--dur-swap) ease-(--ease)"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:leave="transition-opacity duration-(--dur-tap) ease-(--ease)"
            x-transition:leave-end="opacity-0"
        >
            @foreach ($task->subtasks as $subtask)
                <x-task-row :task="$subtask" nested :density="$density" wire:key="subtask-{{ $subtask->id }}" class="border-t border-(--bd)" />
            @endforeach

            <form
                x-data="{ title: '' }"
                @submit.prevent="$wire.createSubtask({{ $task->id }}, title); title = ''"
                class="border-t border-(--bd) py-2 pr-4 pl-[42px]"
            >
                <input
                    type="text"
                    x-model="title"
                    placeholder="+ sous-tâche"
                    class="w-full rounded-full bg-(--surf2) px-3.5 py-1.5 text-[13px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                />
            </form>
        </div>
    @endunless
</div>
