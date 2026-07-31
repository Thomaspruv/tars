@props(['task', 'nested' => false])

@php
    $isDone = $task->status === \App\Enums\TaskStatus::Done;
    $dueDate = $task->due_date ?? $task->scheduled_date;
    $isOverdue = $dueDate && ! $isDone && $dueDate->isPast() && ! $dueDate->isToday();
    $hasSubtasks = ! $nested && $task->subtasks->isNotEmpty();
    $doneSubtasksCount = $hasSubtasks ? $task->subtasks->where('status', \App\Enums\TaskStatus::Done)->count() : 0;
@endphp

<div @if (! $nested) x-data="{ open: false }" @endif {{ $attributes }}>
    <div class="flex items-center gap-[10px] rounded-[8px] px-[10px] py-[8px] hover:bg-(--surf2)">
        @if ($hasSubtasks)
            <button
                type="button"
                @click="open = !open"
                aria-label="Déplier les sous-tâches"
                class="shrink-0 text-(--mut) hover:text-(--tx) focus-visible:outline focus-visible:outline-1 focus-visible:outline-(--ac) focus-visible:outline-offset-2"
            >
                <span aria-hidden="true" class="inline-block text-[10px]" :class="open && 'rotate-180'">▾</span>
            </button>
        @endif

        <button
            type="button"
            wire:click="toggleTask({{ $task->id }})"
            aria-label="{{ $isDone ? 'Marquer non terminée' : 'Marquer terminée' }}"
            class="relative flex size-[16px] shrink-0 items-center justify-center rounded-[5px] border {{ $isDone ? 'border-(--ac) bg-(--ac)' : ($task->is_delegable ? 'border-(--ai)/50' : 'border-(--bd2)') }} focus-visible:outline focus-visible:outline-1 focus-visible:outline-(--ac) focus-visible:outline-offset-2"
        >
            @if ($isDone)
                <span aria-hidden="true" class="text-[10px] leading-none text-(--acT)">✓</span>
            @elseif ($task->is_delegable)
                <span aria-hidden="true" class="text-[9px] leading-none text-(--ai)">◈</span>
            @endif
        </button>

        <span class="flex-1 truncate text-[13.5px] {{ $isDone ? 'text-(--mut) line-through opacity-45' : 'text-(--tx)' }}">
            {{ $task->title }}
        </span>

        <x-badge type="priority" :value="$task->priority" />
        <x-badge type="entity" :entity="$task->entity" />
        <x-badge type="goal" :goal="$task->goal" />
        <x-badge type="recur" :recurrence="$task->recurrence" />

        @if ($task->is_delegable)
            <x-badge type="delegable" />
        @endif

        @if ($hasSubtasks)
            <span class="shrink-0 font-mono text-[11px] text-(--mut)">{{ $doneSubtasksCount }}/{{ $task->subtasks->count() }}</span>
        @endif

        @if ($dueDate)
            <time class="ml-auto shrink-0 font-mono text-[11px] {{ $isOverdue ? 'text-(--warn)' : 'text-(--mut)' }}" datetime="{{ $dueDate->toDateString() }}">
                {{ $isDone ? 'fait' : ($dueDate->isToday() ? "aujourd'hui" : $dueDate->translatedFormat('d M')) }}
            </time>
        @endif

        @isset($actions)
            <div class="{{ $dueDate ? 'shrink-0' : 'ml-auto shrink-0' }}">{{ $actions }}</div>
        @endisset
    </div>

    @unless ($nested)
        <div x-show="open" x-cloak class="ml-[26px] space-y-0.5">
            @foreach ($task->subtasks as $subtask)
                <x-task-row :task="$subtask" nested wire:key="subtask-{{ $subtask->id }}" />
            @endforeach

            <form
                x-data="{ title: '' }"
                @submit.prevent="$wire.createSubtask({{ $task->id }}, title); title = ''"
                class="px-[10px] py-1"
            >
                <input
                    type="text"
                    x-model="title"
                    placeholder="+ sous-tâche"
                    class="w-full rounded-[8px] border border-(--bd2) bg-(--in) px-2 py-1 text-[12.5px] text-(--tx) placeholder:text-(--mut) focus:border-(--ac) focus:outline-none"
                />
            </form>
        </div>
    @endunless
</div>
