<?php

use App\Enums\TaskStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\InboxItem;
use App\Models\QuestionnaireRun;
use App\Models\Review;
use App\Models\Task;
use App\Support\Review\ReviewSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Aujourd\'hui')] class extends Component
{
    #[Computed]
    public function tasksToday(): Collection
    {
        return Task::forToday()
            ->topLevel()
            ->with(['goal', 'entity', 'subtasks.goal', 'subtasks.entity'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('priority')
            ->get();
    }

    #[Computed]
    public function overdueTasks(): Collection
    {
        return $this->tasksToday->filter(
            fn (Task $task): bool => $task->status !== TaskStatus::Done
                && $task->due_date !== null
                && $task->due_date->isPast()
                && ! $task->due_date->isToday(),
        );
    }

    #[Computed]
    public function dueTodayTasks(): Collection
    {
        $overdueIds = $this->overdueTasks->modelKeys();

        return $this->tasksToday->reject(
            fn (Task $task): bool => $task->status === TaskStatus::Done || in_array($task->id, $overdueIds, true),
        );
    }

    #[Computed]
    public function doneTodayTasks(): Collection
    {
        return $this->tasksToday->where('status', TaskStatus::Done);
    }

    #[Computed]
    public function unscheduledTasks(): Collection
    {
        return Task::open()
            ->topLevel()
            ->whereNull('scheduled_date')
            ->whereNull('due_date')
            ->with(['goal', 'entity'])
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();
    }

    #[Computed]
    public function inboxPendingCount(): int
    {
        return InboxItem::whereNull('processed_at')->count();
    }

    #[Computed]
    public function upcomingEvents(): Collection
    {
        return Event::whereBetween('starts_at', [today(), today()->addDays(7)->endOfDay()])
            ->with(['goal', 'entity'])
            ->orderBy('starts_at')
            ->get();
    }

    #[Computed]
    public function pinnedLists(): Collection
    {
        return Checklist::where('is_pinned', true)
            ->with(['items' => fn ($query) => $query->orderBy('position')])
            ->get();
    }

    #[Computed]
    public function pendingReview(): ?Review
    {
        return Review::whereNull('completed_at')->orderByDesc('period_end')->first();
    }

    #[Computed]
    public function pendingBilan(): ?QuestionnaireRun
    {
        return QuestionnaireRun::where('status', 'pending')->orderBy('due_date')->with('questionnaire')->first();
    }

    #[Computed]
    public function daysUntilReview(): int
    {
        $weeklyTime = app(ReviewSettings::class)->weeklyTime();
        $today = today();
        $todayIsDue = $today->isSunday() && now()->format('H:i') < $weeklyTime;
        $nextReview = $todayIsDue ? $today->copy() : $today->copy()->next(Carbon::SUNDAY);

        return $today->diffInDays($nextReview);
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        unset($this->tasksToday);
    }

    public function scheduleForToday(int $taskId): void
    {
        Task::findOrFail($taskId)->update(['scheduled_date' => today()]);

        unset($this->tasksToday, $this->unscheduledTasks);
    }

    public function createSubtask(int $parentTaskId, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        Task::findOrFail($parentTaskId)->subtasks()->create(['title' => $title, 'status' => 'todo']);

        unset($this->tasksToday);
    }

    public function toggleListItem(int $itemId): void
    {
        $item = ChecklistItem::findOrFail($itemId);
        $item->update(['checked_at' => $item->checked_at ? null : now()]);

        unset($this->pinnedLists);
    }
};
?>

<div>
    @php
        $reviewSubtitle = $this->pendingReview
            ? null
            : ($this->daysUntilReview === 0
                ? "Prochaine revue aujourd'hui"
                : 'Prochaine revue dans '.$this->daysUntilReview.' '.Str::plural('jour', $this->daysUntilReview));
    @endphp

    <x-screen-header treatment="work" title="Aujourd'hui" :subtitle="$reviewSubtitle" />

    @if ($this->pendingReview || $this->pendingBilan || $this->inboxPendingCount > 0)
        <div class="mt-4 flex flex-wrap items-center gap-2">
            @if ($this->pendingReview)
                <a
                    href="{{ route('review.index') }}"
                    wire:navigate
                    class="animate-pulse-soft flex items-center gap-1.5 rounded-full border border-(--aibd2) bg-(--aibg) px-3 py-1.5 text-[12.5px] text-(--ai)"
                >
                    <span aria-hidden="true">◈</span> Revue prête à lire
                </a>
            @endif

            @if ($this->pendingBilan)
                <a
                    href="{{ route('bilan.index', ['activeTab' => 'bilans']) }}"
                    wire:navigate
                    class="flex items-center gap-1.5 rounded-full border border-(--ac)/35 bg-(--acbg) px-3 py-1.5 text-[12.5px] text-(--ac)"
                >
                    Bilan en attente : {{ $this->pendingBilan->questionnaire->name }}
                </a>
            @endif

            @if ($this->inboxPendingCount > 0)
                <a
                    href="{{ route('inbox.index') }}"
                    wire:navigate
                    class="flex items-center gap-1.5 rounded-full border border-(--warn)/35 bg-(--warnbg) px-3 py-1.5 text-[12.5px] text-(--warn)"
                >
                    {{ $this->inboxPendingCount }} {{ Str::plural('élément', $this->inboxPendingCount) }} en inbox
                </a>
            @endif
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[1.5fr_1fr]">
        <div>
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-(--tx)">Tâches du jour</h2>
                <span class="font-mono text-xs text-(--mut)">
                    {{ $this->doneTodayTasks->count() }}/{{ $this->tasksToday->count() }}
                </span>
            </div>

            @php
                $tasksTodayTotal = $this->tasksToday->count();
                $tasksTodayPercent = $tasksTodayTotal > 0
                    ? (int) round(($this->doneTodayTasks->count() / $tasksTodayTotal) * 100)
                    : 0;
            @endphp

            @if ($tasksTodayTotal > 0)
                <div class="mt-1.5 h-1 w-full overflow-hidden rounded-full bg-(--surf2)">
                    <div class="h-full rounded-full" style="width: {{ $tasksTodayPercent }}%; background: var(--gradient-signature)"></div>
                </div>
            @endif

            <div class="mt-3 rounded-[12px] border border-(--bd) bg-(--surf)">
                @if ($this->tasksToday->isEmpty())
                    <p class="p-8 text-center text-sm text-(--mut)">Rien à faire aujourd'hui.</p>
                @else
                    @if ($this->overdueTasks->isNotEmpty())
                        <p class="px-4 pt-3 pb-1 font-mono text-[10.5px] font-semibold tracking-[.06em] text-(--warn) uppercase">En retard</p>
                        <div class="divide-y divide-(--bd)">
                            @foreach ($this->overdueTasks as $task)
                                <x-task-row :task="$task" wire:key="task-{{ $task->id }}" />
                            @endforeach
                        </div>
                    @endif

                    @if ($this->dueTodayTasks->isNotEmpty())
                        <div class="divide-y divide-(--bd) {{ $this->overdueTasks->isNotEmpty() ? 'border-t border-(--bd)' : '' }}">
                            @foreach ($this->dueTodayTasks as $task)
                                <x-task-row :task="$task" wire:key="task-{{ $task->id }}" />
                            @endforeach
                        </div>
                    @endif

                    @if ($this->doneTodayTasks->isNotEmpty())
                        <div x-data="{ open: false }" class="border-t border-(--bd)">
                            <button
                                type="button"
                                @click="open = !open"
                                class="flex w-full items-center gap-1.5 px-4 py-2.5 text-left font-mono text-[10.5px] font-semibold tracking-[.06em] text-(--mut) uppercase hover:text-(--tx)"
                            >
                                <span aria-hidden="true" class="inline-block text-[10px]" :class="open && 'rotate-180'">▾</span>
                                Terminées aujourd'hui ({{ $this->doneTodayTasks->count() }})
                            </button>
                            <div x-show="open" x-cloak class="divide-y divide-(--bd) border-t border-(--bd)">
                                @foreach ($this->doneTodayTasks as $task)
                                    <x-task-row :task="$task" wire:key="task-{{ $task->id }}" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            @if ($this->unscheduledTasks->isNotEmpty())
                <div class="mt-6">
                    <div class="flex items-center justify-between">
                        <h2 class="text-base font-semibold text-(--tx)">Sans date</h2>
                        <span class="font-mono text-xs text-(--mut)">{{ $this->unscheduledTasks->count() }}</span>
                    </div>

                    <div class="mt-3 divide-y divide-(--bd) rounded-[12px] border border-(--bd) bg-(--surf)">
                        @foreach ($this->unscheduledTasks as $task)
                            <x-task-row :task="$task" wire:key="unscheduled-{{ $task->id }}">
                                <x-slot:actions>
                                    <x-btn variant="secondary" wire:click="scheduleForToday({{ $task->id }})" class="!px-2.5 !py-1 text-xs">Planifier aujourd'hui</x-btn>
                                </x-slot:actions>
                            </x-task-row>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="space-y-6">
            <div>
                <h2 class="text-base font-semibold text-(--tx)">Événements — 7 jours</h2>
                <div class="mt-3 divide-y divide-(--bd) rounded-[12px] border border-(--bd) bg-(--surf)">
                    @forelse ($this->upcomingEvents as $event)
                        <div class="flex items-center gap-3 px-4 py-3" wire:key="event-{{ $event->id }}">
                            <time class="w-14 shrink-0 font-mono text-xs {{ $event->starts_at->isToday() ? 'text-(--warn)' : 'text-(--mut)' }}" datetime="{{ $event->starts_at->toDateString() }}">
                                {{ $event->starts_at->translatedFormat('D d') }}
                            </time>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-(--tx)">{{ $event->title }}</p>
                            </div>
                            <x-badge type="entity" :entity="$event->entity" />
                            <x-badge type="goal" :goal="$event->goal" />
                        </div>
                    @empty
                        <p class="p-8 text-center text-sm text-(--mut)">Aucun événement à venir.</p>
                    @endforelse
                </div>
            </div>

            @foreach ($this->pinnedLists as $list)
                <div wire:key="list-{{ $list->id }}">
                    <h2 class="text-base font-semibold text-(--tx)">{{ $list->name }}</h2>
                    <div class="mt-3 divide-y divide-(--bd) rounded-[12px] border border-(--bd) bg-(--surf) p-2">
                        @foreach ($list->items as $item)
                            <div class="flex items-center gap-[10px] px-[10px] py-[8px] hover:bg-(--surf2)">
                                <x-checkbox
                                    shape="round"
                                    wire:click="toggleListItem({{ $item->id }})"
                                    @checked($item->checked_at)
                                />
                                <span class="text-[13.5px] {{ $item->checked_at ? 'text-(--mut) line-through opacity-45' : 'text-(--tx)' }}">
                                    {{ $item->content }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
