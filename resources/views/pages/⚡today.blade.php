<?php

use App\Enums\TaskStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
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
            ->with(['goal', 'entity'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('priority')
            ->get();
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
    public function daysUntilReview(): int
    {
        $weeklyTime = app(ReviewSettings::class)->weeklyTime();
        $today = today();
        $todayIsDue = $today->isSunday() && now()->format('H:i') >= $weeklyTime;
        $nextReview = $todayIsDue ? $today->copy() : $today->copy()->next(Carbon::SUNDAY);

        return $today->diffInDays($nextReview);
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

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
    <div class="rounded-[14px] border border-(--bd) p-5 {{ $this->pendingReview ? 'animate-pulse-soft' : '' }}" style="background: linear-gradient(135deg, rgba(83,214,232,.1), rgba(124,108,240,.08))">
        <p class="font-mono text-[10.5px] font-semibold tracking-[.08em] text-(--ai) uppercase">Revue</p>
        @if ($this->pendingReview)
            <p class="mt-1 text-sm text-(--tx)">
                Ta revue est <span class="font-semibold text-(--ac)">prête à lire</span>
                — <a href="{{ route('review.index') }}" wire:navigate class="text-(--ac) underline underline-offset-2">l'ouvrir</a>
            </p>
        @else
            <p class="mt-1 text-sm text-(--tx)">
                @if ($this->daysUntilReview === 0)
                    Prochaine revue <span class="font-semibold text-(--ac)">aujourd'hui</span>
                @else
                    Prochaine revue dans <span class="font-mono font-semibold text-(--ac)">{{ $this->daysUntilReview }}</span> {{ Str::plural('jour', $this->daysUntilReview) }}
                @endif
            </p>
        @endif
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-[1.5fr_1fr]">
        <div>
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-(--tx)">Tâches du jour</h2>
                <span class="font-mono text-xs text-(--mut)">
                    {{ $this->tasksToday->where('status', TaskStatus::Done)->count() }}/{{ $this->tasksToday->count() }}
                </span>
            </div>

            <div class="mt-3 divide-y divide-(--bd) rounded-[14px] border border-(--bd) bg-(--surf)">
                @forelse ($this->tasksToday as $task)
                    <x-task-row :task="$task" wire:key="task-{{ $task->id }}" />
                @empty
                    <p class="p-5 text-center text-sm text-(--mut)">Rien à faire aujourd'hui.</p>
                @endforelse
            </div>
        </div>

        <div class="space-y-6">
            <div>
                <h2 class="text-base font-semibold text-(--tx)">Événements — 7 jours</h2>
                <div class="mt-3 divide-y divide-(--bd) rounded-[14px] border border-(--bd) bg-(--surf)">
                    @forelse ($this->upcomingEvents as $event)
                        <div class="flex items-center gap-3 px-4 py-3" wire:key="event-{{ $event->id }}">
                            <time class="w-14 shrink-0 font-mono text-xs {{ $event->starts_at->isToday() ? 'text-(--warn)' : 'text-(--mut)' }}" datetime="{{ $event->starts_at->toDateString() }}">
                                {{ $event->starts_at->translatedFormat('D d') }}
                            </time>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm text-(--tx)">{{ $event->title }}</p>
                            </div>
                            <x-badge-entity :entity="$event->entity" />
                            <x-badge-goal :goal="$event->goal" />
                        </div>
                    @empty
                        <p class="p-5 text-center text-sm text-(--mut)">Aucun événement à venir.</p>
                    @endforelse
                </div>
            </div>

            @foreach ($this->pinnedLists as $list)
                <div wire:key="list-{{ $list->id }}">
                    <h2 class="text-base font-semibold text-(--tx)">{{ $list->name }}</h2>
                    <div class="mt-3 divide-y divide-(--bd) rounded-[14px] border border-(--bd) bg-(--surf) p-2">
                        @foreach ($list->items as $item)
                            <label class="flex items-center gap-3 px-2.5 py-2 text-sm {{ $item->checked_at ? 'text-(--mut) line-through opacity-45' : 'text-(--tx)' }}">
                                <input
                                    type="checkbox"
                                    wire:click="toggleListItem({{ $item->id }})"
                                    @checked($item->checked_at)
                                    class="size-[16px] rounded-full border-(--bd2) text-(--ac) focus:ring-0"
                                />
                                {{ $item->content }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
