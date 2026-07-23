@props(['task'])

@php
    $isDone = $task->status === \App\Enums\TaskStatus::Done;
    $dueDate = $task->due_date ?? $task->scheduled_date;
    $isOverdue = $dueDate && ! $isDone && $dueDate->isPast() && ! $dueDate->isToday();
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-3 rounded-[10px] px-3.5 py-2.5 hover:bg-(--surf2)']) }}>
    <button
        type="button"
        wire:click="toggleTask({{ $task->id }})"
        aria-label="{{ $isDone ? 'Marquer non terminée' : 'Marquer terminée' }}"
        class="relative flex size-[18px] shrink-0 items-center justify-center rounded-[6px] border border-(--bd2) {{ $isDone ? 'bg-(--ac)' : '' }}"
    >
        @if ($isDone)
            <flux:icon name="check" class="size-3 text-(--acT)" />
        @elseif ($task->is_delegable)
            <span class="text-[10px] text-(--ai)">◈</span>
        @endif
    </button>

    <span class="flex-1 truncate text-sm {{ $isDone ? 'text-(--mut) line-through opacity-45' : 'text-(--tx)' }}">
        {{ $task->title }}
    </span>

    <x-badge-priority :priority="$task->priority" />
    <x-badge-entity :entity="$task->entity" />
    <x-badge-goal :goal="$task->goal" />
    <x-badge-recur :recurrence="$task->recurrence" />

    @if ($task->is_delegable)
        <span class="rounded-[5px] border border-(--ai)/35 px-1.5 py-0.5 font-mono text-[10.5px] text-(--ai)">délégable</span>
    @endif

    @if ($dueDate)
        <time class="ml-auto shrink-0 font-mono text-xs {{ $isOverdue ? 'text-(--warn)' : 'text-(--mut)' }}" datetime="{{ $dueDate->toDateString() }}">
            {{ $dueDate->isToday() ? "aujourd'hui" : $dueDate->translatedFormat('d M') }}
        </time>
    @endif
</div>
