<?php

use App\Enums\GoalStatus;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Note;
use App\Models\Review;
use App\Models\Task;
use App\Support\Brain\BrainSettings;
use App\Support\Goals\GoalRhythm;
use Carbon\CarbonInterface;
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

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function tasks(): Collection
    {
        return $this->goal->tasks()->topLevel()->with(['entity', 'subtasks.goal', 'subtasks.entity'])->orderByRaw('status = "done"')->orderBy('due_date')->get();
    }

    /**
     * Le jalon qui vient — la seule ligne de la fiche mise en avant, parce que
     * c'est la seule qu'on peut cocher aujourd'hui.
     */
    #[Computed]
    public function nextMilestone(): ?Milestone
    {
        return $this->goal->milestones->firstWhere('status', MilestoneStatus::Pending);
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

    /**
     * Le journal de l'objectif : ce qui a bougé, dans l'ordre du temps.
     *
     * Il se lit entièrement dans les données existantes — un jalon coché, une
     * tâche cochée, une note écrite, une revue qui en a parlé. Il n'y a pas de
     * table d'audit dans Tars, donc pas de « reporté deux fois puis
     * supprimé » : ce que la base ne sait pas, le journal ne le raconte pas.
     *
     * @return list<array{at: CarbonInterface, text: string}>
     */
    #[Computed]
    public function journal(): array
    {
        $lines = [];

        foreach ($this->goal->milestones->where('status', MilestoneStatus::Done) as $milestone) {
            $lines[] = ['at' => $milestone->updated_at, 'text' => 'Jalon « '.$milestone->title.' » coché.'];
        }

        $completed = $this->goal->tasks()
            ->withArchived()
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->limit(8)
            ->get(['id', 'title', 'completed_at']);

        foreach ($completed as $task) {
            $lines[] = ['at' => $task->completed_at, 'text' => 'Tâche « '.$task->title.' » cochée.'];
        }

        foreach (Note::where('goal_id', $this->goal->id)->latest()->limit(4)->get() as $note) {
            $lines[] = ['at' => $note->created_at, 'text' => 'Note : '.Str::limit(strip_tags($note->content), 90).''];
        }

        foreach ($this->reviewExcerpts as $excerpt) {
            $lines[] = ['at' => $excerpt['date'], 'text' => 'La revue du '.$excerpt['date']->translatedFormat('j F').' en a parlé.'];
        }

        $lines[] = ['at' => $this->goal->created_at, 'text' => 'Objectif posé.'];

        usort($lines, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return array_slice($lines, 0, 7);
    }

    /**
     * « auj. », « hier », puis la date courte — la colonne de gauche du journal
     * fait 52px, elle ne peut pas porter une date longue.
     */
    public function shortDate(CarbonInterface $at): string
    {
        return match (true) {
            $at->isToday() => 'auj.',
            $at->isYesterday() => 'hier',
            default => $at->translatedFormat('j M'),
        };
    }

    #[Computed]
    public function brainConfigured(): bool
    {
        return app(BrainSettings::class)->isConfigured();
    }

    /**
     * @return Collection<int, \App\Models\BrainDocument>
     */
    #[Computed]
    public function brainDocuments(): Collection
    {
        return $this->goal->brainDocuments()->latest('mtime')->get();
    }

    /**
     * Douze semaines, comme le doc : un trimestre se lit d'un coup d'œil, sept
     * semaines ne disaient pas si la saison était bonne.
     *
     * @return list<int>
     */
    #[Computed]
    public function weeklyActivity(): array
    {
        return GoalRhythm::weeklyActivity($this->goal, 12);
    }

    #[Computed]
    public function lastActivity(): CarbonInterface
    {
        return GoalRhythm::lastActivityAt($this->goal);
    }

    #[Computed]
    public function isStagnant(): bool
    {
        return $this->goal->status === GoalStatus::Active && GoalRhythm::isStagnant($this->lastActivity);
    }

    /**
     * Le nombre de semaines à zéro en fin de série — ce que la légende du
     * rythme dit sous les barres.
     */
    #[Computed]
    public function silentWeeks(): int
    {
        $silent = 0;

        foreach (array_reverse($this->weeklyActivity) as $count) {
            if ($count > 0) {
                break;
            }

            $silent++;
        }

        return $silent;
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        $this->forgetRhythm();
        unset($this->tasks);
    }

    public function createSubtask(int $parentTaskId, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        Task::findOrFail($parentTaskId)->subtasks()->create(['title' => $title, 'status' => 'todo']);

        $this->forgetRhythm();
        unset($this->tasks);
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
        unset($this->nextMilestone);
    }

    public function toggleMilestone(int $milestoneId): void
    {
        $milestone = Milestone::findOrFail($milestoneId);
        $milestone->update(['status' => $milestone->status === MilestoneStatus::Done ? 'pending' : 'done']);

        $this->goal->load('milestones');
        $this->forgetRhythm();
        unset($this->nextMilestone);
    }

    public function deleteMilestone(int $milestoneId): void
    {
        Milestone::findOrFail($milestoneId)->delete();
        $this->goal->load('milestones');
        unset($this->nextMilestone, $this->journal);
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

    private function forgetRhythm(): void
    {
        unset($this->weeklyActivity, $this->lastActivity, $this->isStagnant, $this->silentWeeks, $this->journal);
    }
};
?>

@php
    $milestonesTotal = $goal->milestones->count();
    $milestonesDone = $goal->milestones->where('status', MilestoneStatus::Done)->count();
    $maxActivity = max([1, ...$this->weeklyActivity]);
    $hue = 'var(--goal-'.$goal->color_slot.')';
@endphp

{{-- La fiche est une page de lecture-action : les jalons se cochent à gauche,
     les tâches liées juste en dessous, et la colonne de droite porte ce qu'on
     ne fait que lire — ce que les revues en ont dit, le rythme, le journal. --}}
<div>
    <nav class="mb-1.5 flex items-center gap-2 text-[13px] text-(--mut)" aria-label="Fil d'Ariane">
        <a href="{{ route('goals.index') }}" wire:navigate class="hover:text-(--tx)">Objectifs</a>
        <span aria-hidden="true">/</span>
        <span class="min-w-0 truncate text-(--tx)">{{ $goal->title }}</span>
    </nav>

    <div class="mb-[26px] flex max-w-[1200px] flex-wrap items-start gap-4">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-3">
                <span class="size-[12px] shrink-0 rounded-[3px]" style="background: {{ $hue }}" aria-hidden="true"></span>
                <h1 class="font-display min-w-0 text-[34px] leading-[1.1] font-extrabold tracking-[-.03em] text-(--tx)">{{ $goal->title }}</h1>
            </div>

            {{-- Une seule ligne de contexte, dans l'ordre du doc : d'où ça
                 vient, jusqu'où ça va, depuis quand. --}}
            <p class="mt-2 text-[13px] text-(--mut)">
                @php
                    // L'horizon dit déjà « échéance dépassée » : le répéter
                    // avec la date donnait « échéance dépassée · échéance 30
                    // septembre ». Une échéance passée se dit une seule fois.
                    $overdue = $goal->target_date?->isPast() ?? false;
                @endphp
                {{ collect([
                    $goal->entity?->name,
                    $overdue ? null : $goal->horizon_label,
                    $goal->target_date
                        ? ($overdue ? 'échéance dépassée le ' : 'échéance ').$goal->target_date->translatedFormat('j F')
                        : null,
                    'posé le '.$goal->created_at->translatedFormat('j F Y'),
                ])->filter()->implode(' · ') }}
            </p>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <x-btn variant="secondary" wire:click="openEditModal">Modifier</x-btn>

            <flux:dropdown position="bottom" align="end">
                <button
                    type="button"
                    aria-label="Autres actions sur l'objectif"
                    class="press rounded-full bg-(--surf2) px-3.5 py-[9px] text-[13px] text-(--tx) hover:bg-(--bd2)"
                >···</button>

                <flux:menu>
                    @if ($goal->status !== GoalStatus::Paused)
                        <flux:menu.item as="button" wire:click="updateStatus('paused')" icon="pause">Mettre en pause</flux:menu.item>
                    @else
                        <flux:menu.item as="button" wire:click="updateStatus('active')" icon="play">Reprendre</flux:menu.item>
                    @endif

                    @if ($goal->status !== GoalStatus::Done)
                        <flux:menu.item as="button" wire:click="updateStatus('done')" icon="check">Marquer atteint</flux:menu.item>
                    @endif

                    <flux:menu.separator />

                    <flux:menu.item
                        as="button"
                        variant="danger"
                        icon="trash"
                        wire:click="delete"
                        wire:confirm="Supprimer cet objectif ?"
                    >Supprimer</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    <div class="grid max-w-[1200px] grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="flex min-w-0 flex-col gap-4">
            @if ($goal->description)
                <p class="max-w-[620px] text-[16px] leading-[1.65] text-(--mut)">{{ $goal->description }}</p>
            @endif

            {{-- Les jalons --}}
            <x-card padding="0" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-(--bd) px-[18px] py-3">
                    <h2 class="font-display text-[17px] font-bold text-(--tx)">Jalons</h2>
                    <span class="font-display text-[13px] font-bold tabular-nums text-(--mut)">{{ $milestonesDone }} / {{ $milestonesTotal }}</span>
                </div>

                @foreach ($goal->milestones as $milestone)
                    @php
                        $isDone = $milestone->status === MilestoneStatus::Done;
                        $isNext = $this->nextMilestone?->is($milestone) ?? false;
                    @endphp
                    <div
                        @class([
                            'group flex items-center gap-3.5 border-b border-(--bd) px-[18px] py-[15px] last:border-b-0',
                            'bg-(--acbg)' => $isNext,
                            'opacity-50' => $isDone,
                        ])
                        wire:key="milestone-{{ $milestone->id }}"
                    >
                        <button
                            type="button"
                            wire:click="toggleMilestone({{ $milestone->id }})"
                            wire:target="toggleMilestone({{ $milestone->id }})"
                            wire:loading.attr="disabled"
                            wire:loading.class="scale-90 opacity-60"
                            aria-label="{{ $isDone ? 'Rouvrir le jalon' : 'Marquer le jalon fait' }}"
                            @class([
                                'flex size-[20px] shrink-0 items-center justify-center rounded-[6px] border-2 transition-[background-color,border-color,scale] duration-(--dur-swap) ease-(--ease) active:scale-[.88]',
                                'border-(--ai) bg-(--ai)' => $isDone,
                                'border-(--ac)' => ! $isDone && $isNext,
                                'border-(--bd3)' => ! $isDone && ! $isNext,
                            ])
                        >
                            @if ($isDone)<span aria-hidden="true" class="animate-tick-in text-[11px] leading-none text-(--aiT)">✓</span>@endif
                        </button>

                        <span class="min-w-0 flex-1 text-[15px] {{ $isDone ? 'text-(--tx) line-through' : ($isNext ? 'font-bold text-(--tx)' : 'text-(--tx)') }}">{{ $milestone->title }}</span>

                        @if ($isNext)
                            <span class="shrink-0 text-[11px] text-(--ac) kicker">Le suivant</span>
                        @elseif ($isDone)
                            <span class="shrink-0 text-[12px] text-(--mut)">{{ $this->shortDate($milestone->updated_at) }}</span>
                        @elseif ($milestone->target_date)
                            <span class="shrink-0 text-[12px] text-(--mut)">avant le {{ $milestone->target_date->translatedFormat('j M') }}</span>
                        @else
                            <span class="shrink-0 text-[12px] text-(--mut)" aria-hidden="true">—</span>
                        @endif

                        <button
                            type="button"
                            wire:click="deleteMilestone({{ $milestone->id }})"
                            aria-label="Supprimer le jalon"
                            class="shrink-0 text-[12px] text-(--mut) opacity-0 group-hover:opacity-100 hover:text-(--dgr)"
                        >✕</button>
                    </div>
                @endforeach

                <form wire:submit="addMilestone" class="flex items-center gap-3.5 px-[18px] py-[13px]">
                    <span aria-hidden="true" class="w-[20px] shrink-0 text-center text-(--mut)">+</span>
                    <input
                        type="text"
                        wire:model="newMilestoneTitle"
                        placeholder="Ajouter un jalon"
                        class="w-full bg-transparent text-[15px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                    />
                </form>
                @error('newMilestoneTitle') <p class="px-[18px] pb-3 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </x-card>

            {{-- Les tâches liées : la fiche les coche, elle ne les range pas. --}}
            <x-card padding="0" class="overflow-hidden">
                <div class="flex items-center justify-between border-b border-(--bd) px-[18px] py-3">
                    <h2 class="font-display text-[17px] font-bold text-(--tx)">Tâches liées</h2>
                    <span class="text-[13px] text-(--mut)">{{ $this->tasks->count() }}</span>
                </div>

                @forelse ($this->tasks as $task)
                    @php
                        $isDone = $task->status === TaskStatus::Done;
                        $dueDate = $task->due_date ?? $task->scheduled_date;
                        $isOverdue = $dueDate && ! $isDone && $dueDate->isPast() && ! $dueDate->isToday();
                    @endphp
                    <div class="flex items-center gap-3.5 border-b border-(--bd) px-[18px] py-[13px]" wire:key="task-{{ $task->id }}">
                        <button
                            type="button"
                            wire:click="toggleTask({{ $task->id }})"
                            wire:target="toggleTask({{ $task->id }})"
                            wire:loading.attr="disabled"
                            wire:loading.class="scale-90 opacity-60"
                            aria-label="{{ $isDone ? 'Marquer non terminée' : 'Marquer terminée' }}"
                            class="flex size-[20px] shrink-0 items-center justify-center rounded-[6px] border-2 transition-[background-color,border-color,scale] duration-(--dur-swap) ease-(--ease) active:scale-[.88] {{ $isDone ? 'border-(--ai) bg-(--ai)' : ($isOverdue ? 'border-(--dgr)' : 'border-(--bd3)') }}"
                        >
                            @if ($isDone)<span aria-hidden="true" class="animate-tick-in text-[11px] leading-none text-(--aiT)">✓</span>@endif
                        </button>

                        <span class="min-w-0 flex-1 text-[15px] {{ $isDone ? 'text-(--mut) line-through opacity-60' : 'text-(--tx)' }}">{{ $task->title }}</span>

                        <span class="shrink-0 text-[12px] {{ $isOverdue ? 'text-(--dgr)' : 'text-(--mut)' }}">
                            {{ $dueDate ? $dueDate->translatedFormat('D j') : 'sans date' }}
                        </span>
                    </div>
                @empty
                    <p class="border-b border-(--bd) px-[18px] py-[13px] text-[14px] text-(--mut)">Aucune tâche liée.</p>
                @endforelse

                <form
                    x-data="{ title: '' }"
                    @submit.prevent="$wire.addTask(title); title = ''"
                    class="flex items-center gap-3.5 px-[18px] py-[13px]"
                >
                    <span aria-hidden="true" class="w-[20px] shrink-0 text-center text-(--mut)">+</span>
                    <input
                        type="text"
                        x-model="title"
                        placeholder="Ajouter une tâche"
                        class="w-full bg-transparent text-[15px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                    />
                </form>
            </x-card>
        </div>

        <div class="flex min-w-0 flex-col gap-4">
            @if ($this->reviewExcerpts !== [])
                @foreach ($this->reviewExcerpts as $excerpt)
                    <x-agent-block padding="16" rail="3" wire:key="excerpt-{{ $loop->index }}">
                        <p class="mb-1.5 text-[11px] text-(--ai) kicker">Revue du {{ $excerpt['date']->translatedFormat('j F') }}</p>
                        <p class="text-[14px] leading-[1.55] text-(--tx)">{{ $excerpt['excerpt'] }}</p>
                    </x-agent-block>
                @endforeach
            @endif

            {{-- Le rythme : douze semaines, une barre par semaine. Un
                 histogramme entièrement plat ne dit rien — on le dit alors en
                 français. --}}
            <x-card padding="16">
                <p class="mb-3 text-[11px] text-(--mut) kicker">Rythme · 12 dernières semaines</p>

                @if (array_sum($this->weeklyActivity) === 0)
                    <p class="text-[14px] text-(--mut)">Aucune tâche terminée depuis trois mois.</p>
                @else
                    <div class="mb-2 flex h-[48px] items-end gap-1">
                        @foreach ($this->weeklyActivity as $count)
                            <div
                                @class([
                                    'flex-1 rounded-[3px]',
                                    'bg-(--bd2)' => $count === 0,
                                ])
                                @if ($count > 0) style="height: {{ max(14, (int) round(($count / $maxActivity) * 100)) }}%; background: {{ $hue }};" @else style="height: 8%;" @endif
                                aria-hidden="true"
                            ></div>
                        @endforeach
                    </div>

                    <div class="flex items-baseline justify-between text-[12px] text-(--mut)">
                        <span>{{ now()->subWeeks(11)->translatedFormat('M') }}</span>
                        <span>
                            @if ($this->silentWeeks > 0)
                                {{ $this->silentWeeks }} {{ Str::plural('sem.', $this->silentWeeks) }} à zéro
                            @else
                                {{ array_sum($this->weeklyActivity) }} {{ Str::plural('tâche', array_sum($this->weeklyActivity)) }} cochées
                            @endif
                        </span>
                    </div>
                @endif
            </x-card>

            {{-- Le journal : ce que la base sait, daté. --}}
            <x-card padding="0" class="overflow-hidden">
                <p class="border-b border-(--bd) px-[18px] py-2.5 text-[11px] text-(--mut) kicker">Journal</p>

                @foreach ($this->journal as $line)
                    <div class="flex gap-3 border-b border-(--bd) px-[18px] py-2.5 text-[13px] leading-[1.5] last:border-b-0" wire:key="journal-{{ $loop->index }}">
                        <span class="w-[52px] shrink-0 font-semibold text-(--mut)">{{ $this->shortDate($line['at']) }}</span>
                        <span class="min-w-0 text-(--tx)">{{ $line['text'] }}</span>
                    </div>
                @endforeach
            </x-card>

            @if ($this->relatedEntities->isNotEmpty())
                <div>
                    <p class="mb-2.5 text-[11px] text-(--mut) kicker">Entités rattachées</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->relatedEntities as $entity)
                            <a
                                href="{{ route('entities.show', $entity) }}"
                                wire:navigate
                                wire:key="entity-{{ $entity->id }}"
                                class="press rounded-full bg-(--surf) px-3.5 py-[7px] text-[13px] text-(--tx) hover:bg-(--surf2)"
                            >{{ $entity->name }}</a>
                        @endforeach
                    </div>
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
