<?php

use App\Enums\AgentRunTrigger;
use App\Enums\GoalStatus;
use App\Enums\PlannerProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Event;
use App\Models\Goal;
use App\Models\PlannerProposal;
use App\Models\QuestionnaireRun;
use App\Models\Task;
use App\Support\Momentum\MomentumCalculator;
use App\Support\Momentum\MomentumSettings;
use App\Support\Planner\PlannerAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Aujourd\'hui')] class extends Component
{
    /** Au-delà d'une semaine, une tâche en retard n'est plus de la journée : c'est un stock. */
    private const int LATE_WEEKS = 1;

    #[Computed]
    public function tasksToday(): Collection
    {
        $longOverdueIds = $this->longOverdueTasks->pluck('id')->all();

        return Task::forToday()
            ->topLevel()
            ->when($longOverdueIds !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $longOverdueIds))
            ->with(['goal', 'entity', 'subtasks.goal', 'subtasks.entity'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('priority')
            ->get();
    }

    /**
     * Les retards de plus d'une semaine. Ils ne défilent plus ligne par ligne
     * dans la journée : les lire une par une n'a jamais fait avancer aucune,
     * et ça noyait les cinq tâches du jour.
     */
    #[Computed]
    public function longOverdueTasks(): Collection
    {
        $threshold = today()->subWeeks(self::LATE_WEEKS);

        return Task::open()
            ->topLevel()
            ->where(function (Builder $query) use ($threshold): void {
                $query->whereDate('due_date', '<', $threshold)
                    ->orWhere(function (Builder $query) use ($threshold): void {
                        $query->whereNull('due_date')->whereDate('scheduled_date', '<', $threshold);
                    });
            })
            ->with(['goal', 'entity'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('scheduled_date')
            ->get();
    }

    #[Computed]
    public function openTodayTasks(): Collection
    {
        return $this->tasksToday->where('status', '!=', TaskStatus::Done);
    }

    #[Computed]
    public function doneTodayTasks(): Collection
    {
        return $this->tasksToday->where('status', TaskStatus::Done);
    }

    /**
     * « La suivante » : une seule tâche isolée du reste, celle qui vient.
     * L'écran ne demande pas de choisir — il propose.
     */
    #[Computed]
    public function nextTask(): ?Task
    {
        return $this->openTodayTasks->first();
    }

    #[Computed]
    public function restOfDay(): Collection
    {
        $next = $this->nextTask;

        return $this->tasksToday->reject(fn (Task $task): bool => $next !== null && $task->id === $next->id);
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

    /**
     * La proposition de replanification en attente : ce que le planificateur
     * vient de renvoyer, tant que je ne l'ai ni acceptée ni refusée.
     */
    #[Computed]
    public function plannerProposal(): ?PlannerProposal
    {
        return PlannerProposal::where('status', PlannerProposalStatus::Pending->value)
            ->whereHas('items', fn ($query) => $query->where('status', PlannerProposalStatus::Pending->value))
            ->with([
                'items' => fn ($query) => $query->where('status', PlannerProposalStatus::Pending->value),
                'items.task',
            ])
            ->latest()
            ->first();
    }

    #[Computed]
    public function streakEnabled(): bool
    {
        return app(MomentumSettings::class)->streakEnabled();
    }

    #[Computed]
    public function streak(): int
    {
        return $this->streakEnabled ? app(MomentumCalculator::class)->streakDays() : 0;
    }

    #[Computed]
    public function nextStreakTier(): ?int
    {
        return app(MomentumCalculator::class)->nextStreakTier($this->streak);
    }

    #[Computed]
    public function gauges(): \Illuminate\Support\Collection
    {
        return app(MomentumSettings::class)->gaugesEnabled()
            ? app(MomentumCalculator::class)->bandGauges()
            : collect();
    }

    /**
     * Le titre de la journée est la série elle-même : c'est la seule chose
     * qu'on lit avant d'agir.
     */
    #[Computed]
    public function dayTitle(): string
    {
        $ordinals = [
            2 => 'Deuxième', 3 => 'Troisième', 4 => 'Quatrième', 5 => 'Cinquième',
            6 => 'Sixième', 7 => 'Septième', 8 => 'Huitième', 9 => 'Neuvième', 10 => 'Dixième',
        ];

        return match (true) {
            ! $this->streakEnabled => 'Aujourd\'hui',
            $this->streak === 0 => 'On repart aujourd\'hui',
            $this->streak === 1 => 'Premier jour',
            isset($ordinals[$this->streak]) => $ordinals[$this->streak].' jour d\'affilée',
            default => $this->streak.'e jour d\'affilée',
        };
    }

    /**
     * Le sur-titre porte le chiffre du jour : la date, le compte, la série.
     */
    #[Computed]
    public function kicker(): string
    {
        $parts = [ucfirst(now()->translatedFormat('l j F'))];

        if ($this->tasksToday->isNotEmpty()) {
            $parts[] = $this->doneTodayTasks->count().' sur '.$this->tasksToday->count();
        }

        if ($this->streak > 0) {
            $parts[] = 'la série tient à '.$this->streak.' '.Str::plural('jour', $this->streak);
        }

        return implode(' · ', $parts);
    }

    #[Computed]
    public function upcomingEvents(): Collection
    {
        return Event::whereBetween('starts_at', [today(), today()->addDays(7)->endOfDay()])
            ->with(['goal', 'entity'])
            ->orderBy('starts_at')
            ->limit(6)
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
    public function unpinnedLists(): Collection
    {
        return Checklist::where('is_pinned', false)
            ->withCount(['items as open_items_count' => fn ($query) => $query->whereNull('checked_at')])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function pendingBilan(): ?QuestionnaireRun
    {
        return QuestionnaireRun::where('status', 'pending')->orderBy('due_date')->with('questionnaire')->first();
    }

    /**
     * La célébration : un objectif fermé dans les dernières 48 h prend la tête
     * de l'écran, une fois, puis disparaît.
     */
    #[Computed]
    public function celebratedGoal(): ?Goal
    {
        if (! app(MomentumSettings::class)->celebrationEnabled()) {
            return null;
        }

        return Goal::where('status', GoalStatus::Done->value)
            ->where('updated_at', '>=', now()->subDays(2))
            ->withCount(['milestones', 'tasks'])
            ->orderByDesc('updated_at')
            ->first();
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        unset($this->tasksToday, $this->longOverdueTasks, $this->streak, $this->gauges, $this->dayTitle, $this->kicker);
    }

    public function postponeTask(int $taskId): void
    {
        Task::findOrFail($taskId)->update(['scheduled_date' => today()->addDay()]);

        unset($this->tasksToday, $this->longOverdueTasks, $this->kicker);
    }

    public function scheduleForToday(int $taskId): void
    {
        Task::findOrFail($taskId)->update(['scheduled_date' => today()]);

        unset($this->tasksToday, $this->unscheduledTasks, $this->kicker);
    }

    public function scheduleAllForToday(): void
    {
        Task::open()
            ->topLevel()
            ->whereNull('scheduled_date')
            ->whereNull('due_date')
            ->update(['scheduled_date' => today()]);

        unset($this->tasksToday, $this->unscheduledTasks, $this->kicker);
    }

    /**
     * « Replanifier pour moi » : le planificateur propose des dates pour la
     * semaine qui vient. Il ne touche à rien avant que je dise oui.
     */
    public function replanLate(): void
    {
        $planner = app(PlannerAgent::class);

        if (! $planner->isConfigured()) {
            $this->addError('planner', "Le planificateur n'est pas configuré. Il s'active sur l'écran Agents.");

            return;
        }

        $proposal = $planner->generate(now()->startOfDay(), now()->addDays(6)->endOfDay(), AgentRunTrigger::Manual);

        if (! $proposal) {
            $this->addError('planner', "Le planificateur n'a rien renvoyé d'exploitable. À réessayer dans un moment.");

            return;
        }

        $this->resetErrorBag('planner');

        unset($this->plannerProposal);
    }

    public function applyPlannerProposal(int $proposalId): void
    {
        $proposal = PlannerProposal::with('items.task')->findOrFail($proposalId);

        foreach ($proposal->items->where('status', PlannerProposalStatus::Pending) as $item) {
            $item->task?->update(['scheduled_date' => $item->proposed_scheduled_date]);
            $item->update(['status' => PlannerProposalStatus::Applied]);
        }

        $proposal->update(['status' => PlannerProposalStatus::Applied]);

        unset($this->plannerProposal, $this->tasksToday, $this->longOverdueTasks, $this->unscheduledTasks, $this->kicker);
    }

    public function dismissPlannerProposal(int $proposalId): void
    {
        $proposal = PlannerProposal::findOrFail($proposalId);

        $proposal->items()->where('status', PlannerProposalStatus::Pending->value)->update(['status' => PlannerProposalStatus::Dismissed->value]);
        $proposal->update(['status' => PlannerProposalStatus::Dismissed->value]);

        unset($this->plannerProposal);
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

        unset($this->pinnedLists, $this->unpinnedLists);
    }

    public function addListItem(int $listId, string $content): void
    {
        $content = trim($content);

        if ($content === '') {
            return;
        }

        $list = Checklist::findOrFail($listId);
        $list->items()->create(['content' => $content, 'position' => (int) $list->items()->max('position') + 1]);

        unset($this->pinnedLists, $this->unpinnedLists);
    }

    public function archiveGoal(int $goalId): void
    {
        Goal::findOrFail($goalId)->update(['status' => GoalStatus::Abandoned->value]);

        unset($this->celebratedGoal);
    }
};
?>

<div>
    <x-screen-header :kicker="$this->kicker" :title="$this->dayTitle" class="mb-[18px]" />

    <x-status-band :gauges="$this->gauges" :streak="$this->streak" :next-tier="$this->nextStreakTier" />

    {{-- La journée tient la largeur, le rail prend ce qui reste et passe
         dessous quand l'écran se serre. --}}
    <div class="flex flex-wrap items-start gap-[22px]">
        <div class="min-w-0 flex-[999_1_480px]">
            @if ($this->celebratedGoal)
                <x-card tone="agent" padding="20" class="mb-3.5 flex flex-wrap items-center gap-5">
                    <div class="min-w-0">
                        <p class="mb-2 text-[11px] tracking-[.14em] text-(--ai) uppercase">Objectif atteint</p>
                        <p class="font-display mb-1 text-[23px] font-bold tracking-[-.02em] text-(--tx)">{{ $this->celebratedGoal->title }}</p>
                        <p class="text-[13px] text-(--mut)">
                            {{ $this->celebratedGoal->milestones_count }} {{ Str::plural('jalon', $this->celebratedGoal->milestones_count) }} ·
                            {{ $this->celebratedGoal->tasks_count }} {{ Str::plural('tâche', $this->celebratedGoal->tasks_count) }}
                        </p>
                    </div>
                    <x-btn variant="agent" class="ml-auto" wire:click="archiveGoal({{ $this->celebratedGoal->id }})">Archiver</x-btn>
                </x-card>
            @endif

            {{-- La carte du jour : le compte, la rangée de segments, et une
                 seule tâche mise en avant. Rien d'autre au-dessus de la ligne
                 de flottaison. --}}
            <x-card padding="22" class="mb-3.5">
                <div class="mb-3 flex flex-wrap items-baseline gap-3">
                    <span class="font-display text-[28px] leading-none font-bold text-(--tx)">{{ $this->doneTodayTasks->count() }}</span>
                    <span class="text-[14px] text-(--mut)">
                        @if ($this->tasksToday->isEmpty())
                            aucune tâche posée aujourd'hui — la capture est en ⌘K
                        @else
                            {{ Str::plural('tâche', $this->doneTodayTasks->count()) }} sur {{ $this->tasksToday->count() }} · une seule suffit pour tenir la série
                        @endif
                    </span>
                </div>

                @if ($this->tasksToday->isNotEmpty())
                    <div class="mb-[22px] flex gap-1.5">
                        @foreach ($this->tasksToday->take(10) as $task)
                            <div
                                class="h-[14px] flex-1 rounded-full transition-colors duration-(--dur-swap) ease-(--ease) {{ $task->status === \App\Enums\TaskStatus::Done ? 'bg-(--ac)' : 'bg-(--bd2)' }}"
                                wire:key="segment-{{ $task->id }}"
                                aria-hidden="true"
                            ></div>
                        @endforeach
                    </div>
                @endif

                @if ($this->nextTask)
                    <p class="mb-2 text-[11px] tracking-[.12em] text-(--ac) uppercase">La suivante</p>
                    <div class="flex flex-wrap items-center gap-4">
                        <p class="font-display min-w-0 flex-1 text-[23px] leading-[1.2] font-semibold text-(--tx)">{{ $this->nextTask->title }}</p>
                        <div class="ml-auto flex shrink-0 gap-2.5">
                            <x-btn variant="primary" size="md" wire:click="toggleTask({{ $this->nextTask->id }})">C'est fait</x-btn>
                            <x-btn variant="secondary" size="md" wire:click="postponeTask({{ $this->nextTask->id }})">Demain</x-btn>
                        </div>
                    </div>
                @elseif ($this->tasksToday->isNotEmpty())
                    <p class="text-[15px] text-(--ai)">Tout est posé. La journée est tenue.</p>
                @endif
            </x-card>

            {{-- Le retard, replié. Un bloc, deux issues : le planificateur s'en
                 occupe, ou je vais les voir. Jamais trente lignes à faire
                 défiler avant d'arriver à la journée. --}}
            @if ($this->longOverdueTasks->isNotEmpty())
                @php
                    $lateCount = $this->longOverdueTasks->count();
                    $lateHeadline = $lateCount.' '.Str::plural('tâche', $lateCount).' '.($lateCount > 1 ? 'attendent' : 'attend').' depuis plus d\'une semaine';
                    $lateTitles = $this->longOverdueTasks->take(3)->pluck('title')->implode(', ').($lateCount > 3 ? '…' : '');
                @endphp

                <x-card tone="sunk" padding="18" class="mb-3.5">
                    <p class="text-[15px] leading-[1.35] font-semibold text-(--tx)">{{ $lateHeadline }}</p>

                    <p class="mt-1.5 text-[13px] leading-[1.55] text-(--mut)">{{ $lateTitles }} La plupart ont perdu leur date, pas leur raison d'être.</p>

                    @error('planner')
                        <p class="mt-2.5 text-[13px] text-(--dgr)">{{ $message }}</p>
                    @enderror

                    <div class="mt-3.5 flex flex-wrap items-center gap-2.5">
                        <x-btn variant="agent" wire:click="replanLate" wire:target="replanLate" wire:loading.attr="disabled">
                            <span wire:loading wire:target="replanLate">Je regarde…</span>
                            <span wire:loading.remove wire:target="replanLate">Replanifier pour moi</span>
                        </x-btn>

                        <x-btn variant="secondary" :href="route('tasks.index', ['filter' => 'overdue'])" wire:navigate>Voir les {{ $lateCount }}</x-btn>
                    </div>

                    @if ($this->plannerProposal)
                        <x-agent-block title="Ce que je propose" padding="16" class="mt-3.5">
                            <div class="mb-3 flex flex-col gap-2">
                                @foreach ($this->plannerProposal->items as $item)
                                    <div class="flex flex-wrap items-baseline gap-2 text-[13px]" wire:key="planner-item-{{ $item->id }}">
                                        <span class="min-w-0 flex-1 text-(--tx)">{{ $item->task?->title }}</span>
                                        <span class="shrink-0 text-(--ai)">{{ $item->proposed_scheduled_date->translatedFormat('D j M') }}</span>
                                    </div>
                                @endforeach
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-btn variant="agent" size="xs" wire:click="applyPlannerProposal({{ $this->plannerProposal->id }})">Appliquer</x-btn>
                                <x-btn variant="ghost" size="xs" wire:click="dismissPlannerProposal({{ $this->plannerProposal->id }})">Non merci</x-btn>
                            </div>
                        </x-agent-block>
                    @endif
                </x-card>
            @endif

            @if ($this->restOfDay->isNotEmpty())
                <div class="mb-2.5 flex items-center gap-3 px-1">
                    <h2 class="font-display text-[17px] font-semibold text-(--tx)">Le reste de la journée</h2>
                    <span class="text-[13px] text-(--mut)">{{ $this->restOfDay->count() }}</span>
                </div>

                <x-card padding="0" class="mb-4 overflow-hidden rounded-[16px]">
                    @foreach ($this->restOfDay as $task)
                        <x-task-row
                            :task="$task"
                            wire:key="task-{{ $task->id }}"
                            @class(['border-t border-(--bd)' => ! $loop->first])
                        />
                    @endforeach
                </x-card>
            @endif

            {{-- La réserve : ce qui n'a pas de date. En pilules, pas en lignes
                 — ce n'est pas une liste de travail, c'est un stock. --}}
            @if ($this->unscheduledTasks->isNotEmpty())
                <div class="flex flex-wrap items-center gap-3 px-1 pb-2.5">
                    <h2 class="font-display text-[17px] font-semibold text-(--tx)">En réserve</h2>
                    <span class="text-[13px] text-(--mut)">{{ $this->unscheduledTasks->count() }} sans date</span>
                    <x-btn variant="secondary" class="ml-auto" wire:click="scheduleAllForToday">Tout poser sur aujourd'hui</x-btn>
                </div>

                <div class="flex flex-wrap gap-2.5">
                    @foreach ($this->unscheduledTasks->take(8) as $task)
                        <button
                            type="button"
                            wire:click="scheduleForToday({{ $task->id }})"
                            wire:key="reserve-{{ $task->id }}"
                            title="Poser sur aujourd'hui"
                            class="press rounded-full bg-(--surf) px-4 py-2.5 text-[14px] text-(--mut) hover:bg-(--surf2) hover:text-(--tx)"
                        >{{ $task->title }}</button>
                    @endforeach

                    @if ($this->unscheduledTasks->count() > 8)
                        <a href="{{ route('tasks.index') }}" wire:navigate class="press rounded-full bg-(--surf) px-4 py-2.5 text-[14px] text-(--mut) hover:bg-(--surf2) hover:text-(--tx)">
                            + {{ $this->unscheduledTasks->count() - 8 }} autres
                        </a>
                    @endif
                </div>
            @endif
        </div>

        {{-- Le rail : l'agent parle à un seul endroit, tout en haut, puis
             viennent les événements et les listes épinglées. --}}
        <div class="max-w-[360px] flex-[1_1_300px] space-y-3.5">
            <livewire:proposals-rail />

            @if ($this->pendingBilan)
                <x-agent-block title="Bilan en attente">
                    <p class="mb-3 text-[14px] leading-[1.5] text-(--tx)">{{ $this->pendingBilan->questionnaire->name }}</p>
                    <x-btn variant="agent" :href="route('bilan.run.fill', $this->pendingBilan)" wire:navigate>Remplir</x-btn>
                </x-agent-block>
            @endif

            @if ($this->upcomingEvents->isNotEmpty())
                <x-card padding="18">
                    <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Sept jours</p>
                    <div class="flex flex-col gap-[13px]">
                        @foreach ($this->upcomingEvents as $event)
                            <div class="flex items-center gap-3" wire:key="event-{{ $event->id }}">
                                <span
                                    class="size-[10px] shrink-0 rounded-[3px]"
                                    style="background: var(--goal-{{ $event->goal?->color_slot ?? \App\Support\Palette::slot($event->id) }})"
                                    aria-hidden="true"
                                ></span>
                                <div class="min-w-0">
                                    <p class="truncate text-[14px] text-(--tx)">{{ $event->title }}</p>
                                    <p class="text-[12px] text-(--mut)">{{ $event->starts_at->translatedFormat('l · H:i') }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-card>
            @endif

            @foreach ($this->pinnedLists as $list)
                <x-card padding="18" wire:key="pinned-list-{{ $list->id }}">
                    <div class="mb-3.5 flex items-center gap-2.5">
                        <h2 class="font-display text-[16px] font-semibold text-(--tx)">{{ $list->name }}</h2>
                        <x-badge type="pill" tone="mine" class="!text-[11px]">épinglée</x-badge>
                    </div>

                    @php $openItems = $list->items->whereNull('checked_at'); @endphp

                    @if ($list->items->isNotEmpty() && $openItems->isEmpty())
                        <p class="mb-3.5 text-[14px] text-(--ai)">Vide — tout est fait.</p>
                    @else
                        <div class="flex flex-col gap-3">
                            @foreach ($openItems->take(5) as $item)
                                <div class="flex items-center gap-3 text-[14px]" wire:key="item-{{ $item->id }}">
                                    <x-checkbox
                                        shape="round"
                                        size="18"
                                        wire:click="toggleListItem({{ $item->id }})"
                                        target="toggleListItem({{ $item->id }})"
                                    />
                                    <span class="text-(--tx)">{{ $item->content }}</span>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <form
                        x-data="{ content: '' }"
                        @submit.prevent="$wire.addListItem({{ $list->id }}, content); content = ''"
                        class="mt-3 flex items-center gap-3 border-t border-(--bd) pt-3"
                    >
                        <span aria-hidden="true" class="w-[18px] shrink-0 text-center text-(--mut)">+</span>
                        <input
                            type="text"
                            x-model="content"
                            placeholder="Ajouter"
                            class="w-full bg-transparent text-[14px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                        />
                    </form>
                </x-card>
            @endforeach

            @if ($this->unpinnedLists->isNotEmpty())
                <x-card padding="16" class="text-[13px] leading-[1.9] text-(--mut)">
                    @foreach ($this->unpinnedLists as $list)
                        <a href="{{ route('lists.index') }}" wire:navigate class="block transition-colors duration-(--dur-swap) ease-(--ease) hover:text-(--tx)" wire:key="other-list-{{ $list->id }}">
                            {{ $list->name }} — @if ($list->open_items_count === 0)<span class="text-(--ai)">vide</span>@else{{ $list->open_items_count }}@endif
                        </a>
                    @endforeach
                </x-card>
            @endif
        </div>
    </div>
</div>
