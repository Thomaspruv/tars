<?php

use App\Enums\GoalStatus;
use App\Enums\MilestoneStatus;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Task;
use App\Support\Goals\GoalRhythm;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Objectifs')] class extends Component
{
    /**
     * Les trois états d'un objectif, dits comme on les pense. Le doc de
     * refonte proposait un horizon (trimestre / année) : il n'existe pas en
     * base, et l'inventer ici aurait voulu dire une colonne de plus pour une
     * information que personne n'a encore saisie.
     */
    public const array VIEWS = [
        'en-cours' => ['label' => 'En cours', 'status' => GoalStatus::Active],
        'en-pause' => ['label' => 'En pause', 'status' => GoalStatus::Paused],
        'atteints' => ['label' => 'Atteints', 'status' => GoalStatus::Done],
    ];

    #[Url]
    public string $view = 'en-cours';

    public bool $showCreateModal = false;

    public string $title = '';

    public string $description = '';

    public ?int $entityId = null;

    public ?string $targetDate = null;

    public function mount(): void
    {
        if (! array_key_exists($this->view, self::VIEWS)) {
            $this->view = 'en-cours';
        }
    }

    /**
     * @return Collection<int, Goal>
     */
    #[Computed]
    public function goals(): Collection
    {
        return Goal::where('status', self::VIEWS[$this->view]['status']->value)
            ->orderBy('position')
            ->with(['milestones' => fn ($query) => $query->orderBy('position'), 'entity'])
            ->get();
    }

    /**
     * Quand chaque objectif a bougé pour la dernière fois — l'ordre de la
     * grille en dépend : ce qui dort passe devant, sans rouge.
     *
     * @return array<int, CarbonInterface>
     */
    #[Computed]
    public function lastActivity(): array
    {
        return GoalRhythm::lastActivityFor($this->goals);
    }

    /**
     * @return Collection<int, Goal>
     */
    #[Computed]
    public function orderedGoals(): Collection
    {
        if ($this->view !== 'en-cours') {
            return $this->goals;
        }

        return $this->goals->sortBy(fn (Goal $goal): int => ($this->lastActivity[$goal->id] ?? null)?->getTimestamp() ?? 0)->values();
    }

    /**
     * Les tâches ouvertes de la semaine, par objectif — une requête pour toute
     * la grille.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function weekTaskCounts(): array
    {
        return Task::open()
            ->topLevel()
            ->whereIn('goal_id', $this->goals->pluck('id'))
            ->where(fn ($query) => $query
                ->whereBetween('scheduled_date', [today(), today()->endOfWeek()])
                ->orWhereBetween('due_date', [today(), today()->endOfWeek()]))
            ->selectRaw('goal_id, count(*) as total')
            ->groupBy('goal_id')
            ->pluck('total', 'goal_id')
            ->all();
    }

    #[Computed]
    public function headerCounts(): string
    {
        $label = mb_strtolower(self::VIEWS[$this->view]['label']);
        $moved = collect($this->lastActivity)
            ->filter(fn (CarbonInterface $at): bool => $at->greaterThanOrEqualTo(now()->startOfWeek()))
            ->count();

        if ($this->goals->isEmpty()) {
            return 'aucun objectif '.$label;
        }

        $parts = [$this->goals->count().' '.$label];

        if ($moved > 0) {
            $parts[] = $moved.' '.($moved === 1 ? 'a bougé' : 'ont bougé').' cette semaine';
        }

        return implode(' · ', $parts);
    }

    /**
     * La seule information que l'app ajoute d'elle-même sur cet écran : le
     * premier objectif qui a décroché, et ce qui est déjà prévu pour le
     * relancer. Une phrase, un bouton, et rien d'autre — voir le rail
     * « TARS propose » d'Aujourd'hui, même grammaire.
     *
     * @return array{goal: Goal, text: string}|null
     */
    #[Computed]
    public function idleNote(): ?array
    {
        if ($this->view !== 'en-cours') {
            return null;
        }

        $goal = $this->orderedGoals->first(
            fn (Goal $goal): bool => GoalRhythm::isStagnant($this->lastActivity[$goal->id])
        );

        if (! $goal) {
            return null;
        }

        $weeks = (int) intdiv((int) $this->lastActivity[$goal->id]->startOfDay()->diffInDays(today(), absolute: true), 7);
        $planned = $this->weekTaskCounts[$goal->id] ?? 0;
        $next = $goal->milestones->firstWhere('status', MilestoneStatus::Pending);

        $text = '« '.$goal->title.' » n\'a pas bougé depuis '.$weeks.' semaines.';
        $plannedSentence = $planned === 1 ? '1 tâche est prévue cette semaine' : $planned.' tâches sont prévues cette semaine';

        if ($planned > 0 && $next) {
            $text .= ' '.$plannedSentence.' : si tu '.($planned === 1 ? 'la coches' : 'les coches')
                .', le jalon « '.$next->title.' » tombe.';
        } elseif ($planned > 0) {
            $text .= ' '.$plannedSentence.'.';
        } else {
            $text .= ' Rien n\'est prévu cette semaine non plus.';
        }

        return ['goal' => $goal, 'text' => $text];
    }

    /**
     * @return Collection<int, Entity>
     */
    #[Computed]
    public function allEntities(): Collection
    {
        return Entity::orderBy('name')->get();
    }

    /**
     * Les trois vues, pour le gabarit : `self::` ne résout pas dans la partie
     * Blade d'un composant monofichier.
     *
     * @return array<string, array{label: string, status: GoalStatus}>
     */
    #[Computed]
    public function views(): array
    {
        return self::VIEWS;
    }

    public function showView(string $view): void
    {
        if (! array_key_exists($view, self::VIEWS)) {
            return;
        }

        $this->view = $view;

        unset($this->goals, $this->orderedGoals, $this->lastActivity, $this->weekTaskCounts, $this->headerCounts, $this->idleNote);
    }

    public function openCreateModal(): void
    {
        $this->reset(['title', 'description', 'entityId', 'targetDate']);
        $this->showCreateModal = true;
    }

    public function createGoal(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'entityId' => ['nullable', 'exists:entities,id'],
            'targetDate' => ['nullable', 'date'],
        ]);

        Goal::create([
            'entity_id' => $validated['entityId'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'target_date' => $validated['targetDate'],
        ]);

        $this->showCreateModal = false;
        $this->view = 'en-cours';

        unset($this->goals, $this->orderedGoals, $this->lastActivity, $this->weekTaskCounts, $this->headerCounts, $this->idleNote);
    }
};
?>

{{-- Un objectif, une carte, une jauge de jalons. Le rythme (quand ça a bougé
     pour la dernière fois) est la seule information secondaire : l'objectif qui
     dort depuis trois semaines passe devant, sans rouge. --}}
<div>
    <x-space-tabs space="objectifs" class="mb-3.5" />

    <x-screen-header :kicker="$this->headerCounts" title="Objectifs" class="mb-[22px]">
        <x-slot:actions>
            <div class="flex items-center gap-1 rounded-full bg-(--surf2) p-1">
                @foreach ($this->views as $key => $definition)
                    <button
                        type="button"
                        wire:click="showView('{{ $key }}')"
                        @class([
                            'press rounded-full px-3.5 py-1.5 text-[13px] font-semibold',
                            'bg-(--surf) text-(--tx)' => $this->view === $key,
                            'text-(--mut) hover:text-(--tx)' => $this->view !== $key,
                        ])
                        @if ($this->view === $key) aria-current="true" @endif
                    >{{ $definition['label'] }}</button>
                @endforeach
            </div>

            <x-btn variant="secondary" wire:click="openCreateModal">Nouvel objectif</x-btn>
        </x-slot:actions>
    </x-screen-header>

    @if ($this->idleNote)
        <x-agent-block padding="16" class="mb-[22px]">
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2.5">
                <p class="min-w-[280px] flex-1 text-[14px] leading-[1.5] text-(--tx)">{{ $this->idleNote['text'] }}</p>
                <x-btn variant="agent" size="xs" :href="route('goals.show', $this->idleNote['goal'])" wire:navigate>Voir l'objectif</x-btn>
            </div>
        </x-agent-block>
    @endif

    @if ($this->orderedGoals->isNotEmpty())
        <div
            class="grid items-start gap-3.5"
            {{-- `auto-fill` plutôt que des points de rupture : une carte garde
                 la largeur de la maquette (~560) et la grille en met autant que
                 l'écran en permet — deux sur 1200, quatre sur 2300. Les
                 variantes arbitraires de Tailwind s'ordonnaient après `xl:` et
                 ne prenaient jamais la main. --}}
            style="grid-template-columns: repeat(auto-fill, minmax(560px, 1fr))"
        >
            @foreach ($this->orderedGoals as $goal)
                <x-card-goal
                    :goal="$goal"
                    :last-activity="$this->lastActivity[$goal->id]"
                    :week-tasks="$this->weekTaskCounts[$goal->id] ?? 0"
                    wire:key="goal-{{ $goal->id }}"
                />
            @endforeach

            {{-- La case vide dit ce que l'écran pense : cinq objectifs, c'est
                 déjà beaucoup. Elle n'apparaît que là où l'on en pose. --}}
            @if ($this->view === 'en-cours')
                <button
                    type="button"
                    wire:click="openCreateModal"
                    class="press flex min-h-[132px] items-center justify-center rounded-[18px] border-2 border-dashed border-(--bd2) px-[22px] py-5 text-center text-[14px] leading-[1.5] text-(--mut) hover:border-(--bd3) hover:text-(--tx)"
                >+ Un objectif de plus, ou pas. Cinq, c'est déjà beaucoup.</button>
            @endif
        </div>
    @else
        <x-card tone="dashed" padding="18" class="max-w-[520px]">
            <p class="text-[14px] leading-[1.6] text-(--mut)">
                @if ($this->view === 'en-cours')
                    Aucun objectif ouvert. Poser un objectif est la façon la plus simple de faire remonter une jauge de saison.
                @else
                    Aucun objectif {{ mb_strtolower($this->views[$this->view]['label']) }}.
                @endif
            </p>
            @if ($this->view === 'en-cours')
                <x-btn variant="secondary" class="mt-3.5 self-start" wire:click="openCreateModal">Poser un objectif</x-btn>
            @endif
        </x-card>
    @endif

    <flux:modal wire:model.self="showCreateModal" class="md:w-[420px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvel objectif</flux:heading>

            <div>
                <label class="text-[12px] text-(--mut)">Titre</label>
                <input type="text" wire:model="title" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                @error('title') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Entité liée (optionnel)</label>
                <select wire:model="entityId" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none">
                    <option value="">—</option>
                    @foreach ($this->allEntities as $entity)
                        <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Échéance (optionnel)</label>
                <input type="date" wire:model="targetDate" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Description (optionnel)</label>
                <textarea wire:model="description" rows="3" class="mt-1.5 w-full rounded-[18px] bg-(--surf2) px-4 py-3 text-[14px] text-(--tx) focus:outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createGoal">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
