<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Tâches')] class extends Component
{
    #[Url]
    public string $filter = 'all';

    /** La palette ⌘K atterrit ici avec son terme : la liste doit savoir filtrer. */
    #[Url]
    public string $search = '';

    #[Url]
    public ?int $goalId = null;

    #[Url]
    public ?int $entityId = null;

    #[Url]
    public ?string $priority = null;

    /** Vue exhaustive : on montre aussi ce qui est terminé, sur demande. */
    #[Url]
    public bool $showDone = false;

    /** Le seul écran de l'app qui propose deux densités de ligne. */
    #[Url]
    public bool $compact = true;

    #[Computed]
    public function tasks(): Collection
    {
        $query = Task::query()
            ->topLevel()
            ->with(['goal', 'entity', 'subtasks.goal', 'subtasks.entity'])
            ->orderByRaw('due_date IS NULL')
            ->orderBy('due_date')
            ->orderBy('priority');

        $this->showDone ? $query->where('status', TaskStatus::Done) : $query->open();

        match ($this->filter) {
            'today' => $query->whereDate('scheduled_date', today()),
            'week' => $query->whereBetween('scheduled_date', [today(), today()->addDays(7)]),
            'overdue' => $query->whereDate('due_date', '<', today()),
            'orphan' => $query->orphan(),
            default => null,
        };

        $term = trim($this->search);

        if ($term !== '') {
            $query->where(function (Builder $query) use ($term): void {
                $query->where('title', 'like', "%{$term}%")
                    ->orWhere('notes', 'like', "%{$term}%");
            });
        }

        if ($this->goalId) {
            $query->where('goal_id', $this->goalId);
        }

        if ($this->entityId) {
            $query->where('entity_id', $this->entityId);
        }

        if ($this->priority) {
            $query->where('priority', $this->priority);
        }

        return $query->get();
    }

    /**
     * Les tâches sont regroupées par urgence d'échéance, pas par objectif :
     * cet écran répond à « qu'est-ce qui me tombe dessus », pas à
     * « où j'en suis ». Les groupes vides ne s'affichent pas.
     *
     * @return array<string, Collection>
     */
    #[Computed]
    public function groupedTasks(): array
    {
        $groups = [
            'En retard' => new Collection,
            'Cette semaine' => new Collection,
            'Plus tard' => new Collection,
            'Sans date' => new Collection,
        ];

        foreach ($this->tasks as $task) {
            $date = $task->due_date ?? $task->scheduled_date;

            $key = match (true) {
                $date === null => 'Sans date',
                $date->isPast() && ! $date->isToday() => 'En retard',
                $date->lte(today()->addDays(7)) => 'Cette semaine',
                default => 'Plus tard',
            };

            $groups[$key]->push($task);
        }

        return array_filter($groups, fn (Collection $tasks): bool => $tasks->isNotEmpty());
    }

    /**
     * Le filtre actif est repris en clair dans le titre : on sait toujours ce
     * qu'on regarde sans relire la rangée de pilules.
     */
    #[Computed]
    public function heading(): string
    {
        $parts = [];

        if ($this->goalId) {
            $parts[] = Goal::find($this->goalId)?->title;
        }

        if ($this->entityId) {
            $parts[] = Entity::find($this->entityId)?->name;
        }

        $parts[] = match ($this->filter) {
            'today' => "posées aujourd'hui",
            'week' => 'des sept prochains jours',
            'overdue' => 'en retard',
            'orphan' => 'sans objectif',
            default => null,
        };

        if ($this->priority === 'p1') {
            $parts[] = 'priorité haute';
        }

        $parts = array_values(array_filter($parts));

        if ($parts === []) {
            return $this->showDone ? 'Tout ce qui est fait' : 'Toutes les tâches';
        }

        return ucfirst(implode(', ', $parts));
    }

    #[Computed]
    public function openCount(): int
    {
        return Task::open()->topLevel()->count();
    }

    #[Computed]
    public function orphanRatio(): array
    {
        $total = Task::open()->topLevel()->count();
        $orphan = Task::open()->topLevel()->orphan()->count();

        return [
            'orphan' => $orphan,
            'total' => $total,
            'percent' => $total > 0 ? (int) round(($orphan / $total) * 100) : 0,
        ];
    }

    #[Computed]
    public function allGoals(): Collection
    {
        return Goal::where('status', 'active')->orderBy('title')->get();
    }

    #[Computed]
    public function allEntities(): Collection
    {
        return Entity::orderBy('name')->get();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function clearGoal(): void
    {
        $this->goalId = null;
    }

    public function clearEntity(): void
    {
        $this->entityId = null;
    }

    public function togglePriority(string $priority): void
    {
        $this->priority = $this->priority === $priority ? null : $priority;
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        unset($this->tasks, $this->orphanRatio, $this->openCount);
    }

    public function createSubtask(int $parentTaskId, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        Task::findOrFail($parentTaskId)->subtasks()->create(['title' => $title, 'status' => 'todo']);

        unset($this->tasks, $this->orphanRatio);
    }
};
?>

<div>
    <x-space-tabs class="mb-3.5 -ml-3.5" />

    <x-screen-header
        :kicker="$this->openCount.' '.Str::plural('tâche', $this->openCount).' '.Str::plural('ouverte', $this->openCount).' · '.$this->tasks->count().' '.Str::plural('affichée', $this->tasks->count()).' · '.$this->orphanRatio['orphan'].'/'.$this->orphanRatio['total'].' sans objectif ('.$this->orphanRatio['percent'].'%)'"
        :title="$this->heading"
        class="mb-4"
    >
        <x-slot:actions>
            <x-btn variant="secondary" wire:click="$toggle('compact')">
                Densité : {{ $compact ? 'compacte' : 'confortable' }}
            </x-btn>
        </x-slot:actions>
    </x-screen-header>

    {{-- Les filtres sont des pilules qui s'allument. Une pilule allumée porte
         sa croix : on la retire là où on l'a posée. --}}
    <div class="mb-4 flex flex-wrap items-center gap-2 border-b border-(--bd) pb-4">
        <label class="flex items-center gap-1.5 rounded-full bg-(--surf) px-3.5 py-[7px] text-[13px] text-(--mut)">
            <span class="sr-only">Rechercher dans les tâches</span>
            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Rechercher…"
                class="w-[124px] bg-transparent text-(--tx) placeholder:text-(--mut) focus:outline-none"
            />
        </label>

        <span class="mx-1.5 h-5 w-px self-center bg-(--bd)" aria-hidden="true"></span>

        <button
            type="button"
            wire:click="$set('showDone', false)"
            class="press rounded-full px-3.5 py-[7px] text-[13px] {{ $showDone ? 'bg-(--surf) text-(--mut)' : 'bg-(--acbg) font-semibold text-(--ac)' }}"
        >Ouvertes</button>
        <button
            type="button"
            wire:click="$set('showDone', true)"
            class="press rounded-full px-3.5 py-[7px] text-[13px] {{ $showDone ? 'bg-(--acbg) font-semibold text-(--ac)' : 'bg-(--surf) text-(--mut)' }}"
        >Terminées</button>

        <span class="mx-1.5 h-5 w-px self-center bg-(--bd)" aria-hidden="true"></span>

        @foreach (['today' => "Aujourd'hui", 'week' => 'Cette semaine', 'overdue' => 'En retard', 'orphan' => 'Sans objectif'] as $value => $label)
            <button
                type="button"
                wire:click="setFilter('{{ $filter === $value ? 'all' : $value }}')"
                class="press rounded-full px-3.5 py-[7px] text-[13px] {{ $filter === $value ? 'bg-(--acbg) font-semibold text-(--ac)' : 'bg-(--surf) text-(--mut)' }}"
            >{{ $label }}@if ($filter === $value) ✕ @endif</button>
        @endforeach

        <span class="mx-1.5 h-5 w-px self-center bg-(--bd)" aria-hidden="true"></span>

        @if ($goalId)
            @php $activeGoal = $this->allGoals->firstWhere('id', $goalId); @endphp
            <button
                type="button"
                wire:click="clearGoal"
                class="press flex items-center gap-2 rounded-full bg-(--acbg) px-3.5 py-[7px] text-[13px] font-semibold text-(--ac)"
            >
                @if ($activeGoal)
                    <span class="size-[8px] rounded-full" style="background: var(--goal-{{ $activeGoal->color_slot }})"></span>{{ $activeGoal->title }}
                @endif
                ✕
            </button>
        @else
            <label class="rounded-full bg-(--surf) px-3.5 py-[7px] text-[13px] text-(--mut)">
                <span class="sr-only">Filtrer par objectif</span>
                <select wire:model.live="goalId" class="bg-transparent focus:outline-none">
                    <option value="">Objectif</option>
                    @foreach ($this->allGoals as $goal)
                        <option value="{{ $goal->id }}">{{ $goal->title }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($entityId)
            <button
                type="button"
                wire:click="clearEntity"
                class="press rounded-full bg-(--acbg) px-3.5 py-[7px] text-[13px] font-semibold text-(--ac)"
            >{{ $this->allEntities->firstWhere('id', $entityId)?->name }} ✕</button>
        @else
            <label class="rounded-full bg-(--surf) px-3.5 py-[7px] text-[13px] text-(--mut)">
                <span class="sr-only">Filtrer par entité</span>
                <select wire:model.live="entityId" class="bg-transparent focus:outline-none">
                    <option value="">Entité</option>
                    @foreach ($this->allEntities as $entity)
                        <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @foreach (TaskPriority::cases() as $case)
            <button
                type="button"
                wire:click="togglePriority('{{ $case->value }}')"
                class="press rounded-full px-3.5 py-[7px] text-[13px] {{ $priority === $case->value ? 'bg-(--acbg) font-semibold text-(--ac)' : 'bg-(--surf) text-(--mut)' }}"
            >{{ strtoupper($case->value) }}@if ($priority === $case->value) ✕ @endif</button>
        @endforeach

        <span class="ml-auto px-3.5 py-[7px] text-[13px] text-(--mut)">Trié par échéance</span>
    </div>

    @forelse ($this->groupedTasks as $label => $tasks)
        <div class="mb-2 flex items-center gap-2.5" wire:key="group-{{ Str::slug($label) }}">
            <span class="text-[11px] tracking-[.12em] uppercase {{ $label === 'En retard' ? 'text-(--dgr)' : 'text-(--mut)' }}">{{ $label }}</span>
            <span class="text-[12px] text-(--mut)">{{ $tasks->count() }}</span>
        </div>

        <div class="mb-[22px] flex flex-col">
            @foreach ($tasks as $task)
                <x-task-row
                    :task="$task"
                    :density="$compact ? 'compact' : 'comfortable'"
                    wire:key="task-{{ $task->id }}"
                    class="press-row border-b border-(--bd)"
                />
            @endforeach
        </div>
    @empty
        <p class="py-10 text-[15px] text-(--mut)">Aucune tâche ici.</p>
    @endforelse
</div>
