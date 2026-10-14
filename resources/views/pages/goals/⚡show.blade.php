<?php

use App\Enums\GoalStatus;
use App\Enums\MilestoneStatus;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Review;
use App\Models\Task;
use App\Support\Brain\BrainSettings;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Objectif')] class extends Component
{
    public Goal $goal;

    public string $newMilestoneTitle = '';

    public bool $showEditModal = false;

    public string $editTitle = '';

    public string $editDescription = '';

    public ?string $editTargetDate = null;

    public function mount(): void
    {
        $this->goal->load(['milestones' => fn ($query) => $query->orderBy('position'), 'entity']);
    }

    #[Computed]
    public function tasks(): Collection
    {
        return $this->goal->tasks()->topLevel()->with(['entity', 'subtasks.goal', 'subtasks.entity'])->orderByRaw('status = "done"')->orderBy('due_date')->get();
    }

    /**
     * Toutes les entités que cet objectif touche : la sienne, plus celles de
     * ses tâches. C'est le carnet d'adresses de l'objectif.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Entity>
     */
    #[Computed]
    public function relatedEntities(): \Illuminate\Support\Collection
    {
        return collect([$this->goal->entity])
            ->concat($this->tasks->pluck('entity'))
            ->filter()
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    /**
     * Ce que les revues passées ont dit de cet objectif. On ne garde que les
     * paragraphes qui le nomment : c'est de la parole d'agent, citée et datée,
     * pas un résumé qu'on réécrirait ici.
     *
     * @return list<array{date: \Illuminate\Support\Carbon, excerpt: string}>
     */
    #[Computed]
    public function reviewExcerpts(): array
    {
        $needle = mb_strtolower($this->goal->title);

        return Review::whereNotNull('generated_content')
            ->orderByDesc('period_end')
            ->limit(20)
            ->get()
            ->map(function (Review $review) use ($needle): ?array {
                foreach (preg_split('/\n{2,}/', (string) $review->generated_content) ?: [] as $paragraph) {
                    $paragraph = trim(strip_tags($paragraph));

                    if ($paragraph !== '' && str_contains(mb_strtolower($paragraph), $needle)) {
                        return ['date' => $review->period_end, 'excerpt' => Str::limit($paragraph, 240)];
                    }
                }

                return null;
            })
            ->filter()
            ->take(3)
            ->values()
            ->all();
    }

    #[Computed]
    public function brainConfigured(): bool
    {
        return app(BrainSettings::class)->isConfigured();
    }

    #[Computed]
    public function brainDocuments(): Collection
    {
        return $this->goal->brainDocuments()->latest('mtime')->get();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function weeklyActivity(): array
    {
        $completed = $this->goal->tasks()
            ->withArchived()
            ->where('status', 'done')
            ->where('completed_at', '>=', now()->subWeeks(7))
            ->get(['completed_at']);

        return collect(range(6, 0))->map(function (int $weeksAgo) use ($completed): int {
            $weekStart = now()->subWeeks($weeksAgo)->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();

            return $completed->filter(fn ($task) => $task->completed_at->between($weekStart, $weekEnd))->count();
        })->all();
    }

    #[Computed]
    public function isSlowing(): bool
    {
        $activity = $this->weeklyActivity;
        $recent = array_sum(array_slice($activity, -2));
        $before = array_sum(array_slice($activity, 0, -2));

        return $before > 0 && $recent * 2 < $before / max(1, count($activity) - 2) * 2;
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        unset($this->tasks, $this->weeklyActivity, $this->isSlowing);
    }

    public function createSubtask(int $parentTaskId, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        Task::findOrFail($parentTaskId)->subtasks()->create(['title' => $title, 'status' => 'todo']);

        unset($this->tasks, $this->weeklyActivity);
    }

    public function addTask(string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        $this->goal->tasks()->create(['title' => $title, 'status' => 'todo']);

        unset($this->tasks);
    }

    public function addMilestone(): void
    {
        $this->validate(['newMilestoneTitle' => ['required', 'string', 'max:255']]);

        $this->goal->milestones()->create([
            'title' => $this->newMilestoneTitle,
            'position' => $this->goal->milestones->count(),
        ]);

        $this->newMilestoneTitle = '';
        $this->goal->load('milestones');
    }

    public function toggleMilestone(int $milestoneId): void
    {
        $milestone = Milestone::findOrFail($milestoneId);
        $milestone->update(['status' => $milestone->status === MilestoneStatus::Done ? 'pending' : 'done']);

        $this->goal->load('milestones');
    }

    public function deleteMilestone(int $milestoneId): void
    {
        Milestone::findOrFail($milestoneId)->delete();
        $this->goal->load('milestones');
    }

    public function updateStatus(string $status): void
    {
        $this->goal->update(['status' => $status]);
    }

    public function openEditModal(): void
    {
        $this->editTitle = $this->goal->title;
        $this->editDescription = (string) $this->goal->description;
        $this->editTargetDate = $this->goal->target_date?->toDateString();
        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $validated = $this->validate([
            'editTitle' => ['required', 'string', 'max:255'],
            'editDescription' => ['nullable', 'string'],
            'editTargetDate' => ['nullable', 'date'],
        ]);

        $this->goal->update([
            'title' => $validated['editTitle'],
            'description' => $validated['editDescription'],
            'target_date' => $validated['editTargetDate'],
        ]);

        $this->showEditModal = false;
    }

    public function delete()
    {
        $this->goal->delete();

        return $this->redirect(route('goals.index'), navigate: true);
    }
};
?>

@php
    $milestonesTotal = $goal->milestones->count();
    $milestonesDone = $goal->milestones->where('status', MilestoneStatus::Done)->count();
    $maxActivity = max([1, ...$this->weeklyActivity]);
@endphp

{{-- Registre fiche : pas de bande d'état, une colonne de lecture, du blanc.
     Un objet, son contexte, ses relations. --}}
<div>
    <div class="mb-[26px] flex max-w-[1200px] flex-wrap items-center gap-3.5">
        <a href="{{ route('goals.index') }}" wire:navigate class="text-[13px] text-(--mut) hover:text-(--tx)">← Objectifs</a>

        <span class="flex items-center gap-2 text-[13px] text-(--mut)">
            <span class="size-[8px] rounded-full" style="background: var(--goal-{{ $goal->color_slot }})" aria-hidden="true"></span>
            {{ $goal->entity?->name ?? 'Sans entité' }}
        </span>

        <div class="ml-auto flex flex-wrap gap-2">
            @if ($goal->status !== GoalStatus::Paused)
                <x-btn variant="secondary" wire:click="updateStatus('paused')">Mettre en pause</x-btn>
            @else
                <x-btn variant="secondary" wire:click="updateStatus('active')">Reprendre</x-btn>
            @endif
            @if ($goal->status !== GoalStatus::Done)
                <x-btn variant="secondary" wire:click="updateStatus('done')">Marquer atteint</x-btn>
            @endif
            <x-btn variant="secondary" wire:click="openEditModal">Modifier</x-btn>
            <x-btn variant="danger" wire:click="delete" wire:confirm="Supprimer cet objectif ?">Supprimer</x-btn>
        </div>
    </div>

    <div class="grid max-w-[1200px] grid-cols-1 gap-8 xl:grid-cols-[1fr_320px] xl:gap-12">
        <div class="max-w-[620px]">
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <span class="text-[11px] tracking-[.12em] uppercase {{ $goal->status === GoalStatus::Paused ? 'text-(--mut)' : 'text-(--ai)' }}">
                    {{ $goal->status === GoalStatus::Done ? 'Atteint' : ($goal->status === GoalStatus::Paused ? 'En pause' : 'En cours') }}
                </span>
                @if ($goal->target_date)
                    <span class="text-[13px] text-(--mut)">
                        cible : {{ $goal->target_date->translatedFormat('j F') }} ·
                        {{ $goal->target_date->isPast() ? 'dépassée' : 'dans '.(int) ceil(today()->diffInWeeks($goal->target_date)).' semaines' }}
                    </span>
                @endif
            </div>

            <h1 class="font-display mb-4 text-[38px] leading-[1.1] font-bold tracking-[-.03em] text-(--tx)">{{ $goal->title }}</h1>

            @if ($goal->description)
                <p class="mb-[30px] text-[16px] leading-[1.65] text-(--mut)">{{ $goal->description }}</p>
            @endif

            <div class="mb-3.5 flex flex-wrap items-baseline gap-3">
                <h2 class="font-display text-[20px] font-semibold text-(--tx)">Jalons</h2>
                <span class="text-[13px] text-(--mut)">{{ $milestonesDone }} sur {{ $milestonesTotal }}</span>
                @if ($milestonesTotal > 0)
                    <div class="ml-auto flex w-[200px] gap-[5px]">
                        @foreach ($goal->milestones as $milestone)
                            <div
                                class="h-[8px] flex-1 rounded-full {{ $milestone->status === MilestoneStatus::Done ? 'bg-(--ai)' : 'bg-(--bd2)' }}"
                                aria-hidden="true"
                            ></div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="mb-[30px] flex flex-col">
                @foreach ($goal->milestones as $milestone)
                    @php $isDone = $milestone->status === MilestoneStatus::Done; @endphp
                    <div
                        class="group flex items-center gap-3.5 border-b border-(--bd) px-1 py-[13px] transition-opacity duration-(--dur-swap) ease-(--ease) {{ $isDone ? 'opacity-50' : '' }}"
                        wire:key="milestone-{{ $milestone->id }}"
                    >
                        <button
                            type="button"
                            wire:click="toggleMilestone({{ $milestone->id }})"
                            wire:target="toggleMilestone({{ $milestone->id }})"
                            wire:loading.attr="disabled"
                            wire:loading.class="scale-90 opacity-60"
                            aria-label="{{ $isDone ? 'Rouvrir le jalon' : 'Marquer le jalon fait' }}"
                            class="flex size-[19px] shrink-0 items-center justify-center rounded-[6px] border-2 transition-[background-color,border-color,scale] duration-(--dur-swap) ease-(--ease) active:scale-[.88] {{ $isDone ? 'border-(--ai) bg-(--ai)' : 'border-(--bd3)' }}"
                        >
                            @if ($isDone)<span aria-hidden="true" class="animate-tick-in text-[11px] leading-none text-(--aiT)">✓</span>@endif
                        </button>

                        <span class="min-w-0 flex-1 text-[15px] {{ $isDone ? 'text-(--tx) line-through' : 'text-(--tx)' }}">{{ $milestone->title }}</span>

                        @if ($isDone)
                            <span class="shrink-0 text-[12px] text-(--mut)">fait</span>
                        @endif

                        <button
                            type="button"
                            wire:click="deleteMilestone({{ $milestone->id }})"
                            aria-label="Supprimer le jalon"
                            class="shrink-0 text-[12px] text-(--mut) opacity-0 group-hover:opacity-100 hover:text-(--dgr)"
                        >✕</button>
                    </div>
                @endforeach

                <form wire:submit="addMilestone" class="flex items-center gap-3.5 px-1 py-3">
                    <span aria-hidden="true" class="w-[19px] shrink-0 text-center text-(--mut)">+</span>
                    <input
                        type="text"
                        wire:model="newMilestoneTitle"
                        placeholder="Ajouter un jalon"
                        class="w-full bg-transparent text-[15px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                    />
                </form>
                @error('newMilestoneTitle') <p class="px-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div class="mb-3 flex flex-wrap items-baseline gap-3">
                <h2 class="font-display text-[20px] font-semibold text-(--tx)">Rythme</h2>
                @if ($this->isSlowing)
                    <span class="text-[13px] text-(--warn)">le rythme ralentit depuis deux semaines</span>
                @endif
            </div>

            {{-- Un histogramme entièrement plat ne dit rien : on préfère le
                 dire en français. --}}
            @if (array_sum($this->weeklyActivity) === 0)
                <p class="text-[14px] text-(--mut)">Aucune tâche terminée sur les sept dernières semaines.</p>
            @else
                <div class="mb-2 flex h-[60px] items-end gap-1.5">
                    @foreach ($this->weeklyActivity as $count)
                        <div
                            class="flex-1 rounded-t-[4px] {{ $loop->last && $this->isSlowing ? 'bg-(--warn)' : 'bg-(--bd2)' }}"
                            style="height: {{ max(12, (int) round(($count / $maxActivity) * 100)) }}%"
                            aria-hidden="true"
                        ></div>
                    @endforeach
                </div>
                <p class="text-[12px] text-(--mut)">Tâches terminées par semaine · 7 dernières semaines</p>
            @endif
        </div>

        <div>
            <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Tâches ouvertes</p>
            <div class="mb-[30px] flex flex-col gap-3">
                @foreach ($this->tasks as $task)
                    <div class="flex items-center gap-3 text-[14px]" wire:key="task-{{ $task->id }}">
                        @php
                            $isDone = $task->status === \App\Enums\TaskStatus::Done;
                            $dueDate = $task->due_date ?? $task->scheduled_date;
                            $isOverdue = $dueDate && ! $isDone && $dueDate->isPast() && ! $dueDate->isToday();
                        @endphp
                        <button
                            type="button"
                            wire:click="toggleTask({{ $task->id }})"
                            wire:target="toggleTask({{ $task->id }})"
                            wire:loading.attr="disabled"
                            wire:loading.class="scale-90 opacity-60"
                            aria-label="{{ $isDone ? 'Marquer non terminée' : 'Marquer terminée' }}"
                            class="flex size-[17px] shrink-0 items-center justify-center rounded-[6px] border-2 transition-[background-color,border-color,scale] duration-(--dur-swap) ease-(--ease) active:scale-[.88] {{ $isDone ? 'border-(--ai) bg-(--ai)' : ($isOverdue ? 'border-(--dgr)' : 'border-(--bd3)') }}"
                        >
                            @if ($isDone)<span aria-hidden="true" class="animate-tick-in text-[10px] leading-none text-(--aiT)">✓</span>@endif
                        </button>
                        <span class="min-w-0 flex-1 {{ $isDone ? 'text-(--mut) line-through opacity-50' : 'text-(--tx)' }}">{{ $task->title }}</span>
                    </div>
                @endforeach

                <form
                    x-data="{ title: '' }"
                    @submit.prevent="$wire.addTask(title); title = ''"
                    class="flex items-center gap-3 text-[14px]"
                >
                    <span aria-hidden="true" class="w-[17px] shrink-0 text-center text-(--mut)">+</span>
                    <input
                        type="text"
                        x-model="title"
                        placeholder="Ajouter une tâche"
                        class="w-full bg-transparent text-(--tx) placeholder:text-(--mut) focus:outline-none"
                    />
                </form>
            </div>

            @if ($this->relatedEntities->isNotEmpty())
                <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Entités rattachées</p>
                <div class="mb-[30px] flex flex-wrap gap-2">
                    @foreach ($this->relatedEntities as $entity)
                        <a
                            href="{{ route('entities.show', $entity) }}"
                            wire:navigate
                            wire:key="entity-{{ $entity->id }}"
                            class="press rounded-full bg-(--surf) px-3.5 py-[7px] text-[13px] text-(--tx) hover:bg-(--surf2)"
                        >{{ $entity->name }}</a>
                    @endforeach
                </div>
            @endif

            @if ($this->reviewExcerpts !== [])
                <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Ce que les revues en ont dit</p>
                <div class="mb-[30px] flex flex-col gap-3">
                    @foreach ($this->reviewExcerpts as $excerpt)
                        <x-agent-block
                            padding="14"
                            rail="3"
                            class="!rounded-[14px]"
                            wire:key="excerpt-{{ $loop->index }}"
                        >
                            <p class="mb-[5px] text-[12px] text-(--ai)">Revue du {{ $excerpt['date']->translatedFormat('j F') }}</p>
                            <p class="text-[13px] leading-[1.55] text-(--tx)">{{ $excerpt['excerpt'] }}</p>
                        </x-agent-block>
                    @endforeach
                </div>
            @endif

            @if ($this->brainConfigured)
                <x-brain-notes :documents="$this->brainDocuments" />
            @endif
        </div>
    </div>

    <flux:modal wire:model.self="showEditModal" class="md:w-[420px]">
        <div class="space-y-5" wire:loading.class="opacity-50" wire:target="saveEdit">
            <flux:heading size="lg">Modifier l'objectif</flux:heading>

            <div>
                <label class="text-[12px] text-(--mut)">Titre</label>
                <input type="text" wire:model="editTitle" wire:loading.attr="disabled" wire:target="saveEdit" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                @error('editTitle') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Échéance</label>
                <input type="date" wire:model="editTargetDate" wire:loading.attr="disabled" wire:target="saveEdit" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Description</label>
                <textarea wire:model="editDescription" wire:loading.attr="disabled" wire:target="saveEdit" rows="3" class="mt-1.5 w-full rounded-[18px] bg-(--surf2) px-4 py-3 text-[14px] text-(--tx) focus:outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showEditModal', false)" wire:loading.attr="disabled" wire:target="saveEdit">Annuler</x-btn>
                <x-btn variant="primary" wire:click="saveEdit" wire:loading.attr="disabled" wire:target="saveEdit">
                    <span wire:loading wire:target="saveEdit">Enregistrement…</span>
                    <span wire:loading.remove wire:target="saveEdit">Enregistrer</span>
                </x-btn>
            </div>
        </div>
    </flux:modal>
</div>
