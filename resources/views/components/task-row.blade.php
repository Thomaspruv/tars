@props(['task'])

@php
    $isDone = $task->status === \App\Enums\TaskStatus::Done;
    $dueDate = $task->due_date ?? $task->scheduled_date;
    $isOverdue = $dueDate && ! $isDone && $dueDate->isPast() && ! $dueDate->isToday();
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-[10px] rounded-[8px] px-[10px] py-[8px] hover:bg-(--surf2)']) }}>
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

    @if ($dueDate)
        <time class="ml-auto shrink-0 font-mono text-[11px] {{ $isOverdue ? 'text-(--warn)' : 'text-(--mut)' }}" datetime="{{ $dueDate->toDateString() }}">
            {{ $isDone ? 'fait' : ($dueDate->isToday() ? "aujourd'hui" : $dueDate->translatedFormat('d M')) }}
        </time>
    @endif
</div>
