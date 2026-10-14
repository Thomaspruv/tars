<?php

use App\Enums\GoalStatus;
use App\Models\Entity;
use App\Models\Goal;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Objectifs')] class extends Component
{
    public bool $showCreateModal = false;

    public string $title = '';

    public string $description = '';

    public ?int $entityId = null;

    public ?string $targetDate = null;

    /**
     * Les objectifs sont groupés par état, pas par domaine : « où j'en suis »
     * se lit d'abord dans ce qui avance, ce qui dort et ce qui est fini.
     *
     * @return array<string, Collection>
     */
    #[Computed]
    public function groupedGoals(): array
    {
        $groups = [
            'En cours' => $this->goals->where('status', GoalStatus::Active),
            'En pause' => $this->goals->where('status', GoalStatus::Paused),
            'Atteints' => $this->goals->where('status', GoalStatus::Done),
        ];

        return array_filter($groups, fn (Collection $goals): bool => $goals->isNotEmpty());
    }

    #[Computed]
    public function headerCounts(): string
    {
        $parts = [];

        foreach ($this->groupedGoals as $label => $goals) {
            $parts[] = $goals->count().' '.mb_strtolower($label);
        }

        return $parts === [] ? 'aucun objectif' : implode(' · ', $parts);
    }

    #[Computed]
    public function goals(): Collection
    {
        return Goal::orderBy('position')->with([
            'milestones',
            'tasks' => fn ($query) => $query->where('status', 'done')
                ->where('completed_at', '>=', now()->subWeeks(6))
                ->select('id', 'goal_id', 'completed_at'),
        ])->get();
    }

    #[Computed]
    public function allEntities(): Collection
    {
        return Entity::orderBy('name')->get();
    }

    public function weeklyActivityFor(Goal $goal): array
    {
        return collect(range(5, 0))->map(function (int $weeksAgo) use ($goal): int {
            $weekStart = now()->subWeeks($weeksAgo)->startOfWeek();
            $weekEnd = $weekStart->copy()->endOfWeek();

            return $goal->tasks->filter(fn ($task) => $task->completed_at->between($weekStart, $weekEnd))->count();
        })->all();
    }

    public function isStagnant(Goal $goal): bool
    {
        return $goal->status === GoalStatus::Active
            && $goal->tasks->where('completed_at', '>=', now()->subWeeks(3))->isEmpty();
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
        unset($this->goals);
    }
};
?>

<div>
    <x-screen-header :kicker="$this->headerCounts" title="Objectifs" class="mb-[22px]">
        <x-slot:actions>
            <x-btn variant="secondary" wire:click="openCreateModal">Nouvel objectif</x-btn>
        </x-slot:actions>
    </x-screen-header>

    @forelse ($this->groupedGoals as $label => $goals)
        <div class="mb-3 flex items-center gap-2.5" wire:key="group-{{ Str::slug($label) }}">
            <h2 class="font-display text-[17px] font-semibold {{ $label === 'En pause' ? 'text-(--mut)' : 'text-(--tx)' }}">{{ $label }}</h2>
            <span class="text-[13px] text-(--mut)">{{ $goals->count() }} {{ Str::plural('objectif', $goals->count()) }}</span>
        </div>

        <div class="mb-6 grid items-start gap-3.5" style="grid-template-columns: repeat(auto-fill, minmax(320px, 1fr))">
            @foreach ($goals as $goal)
                <x-card-goal
                    :goal="$goal"
                    :weekly-activity="$this->weeklyActivityFor($goal)"
                    :stagnant="$this->isStagnant($goal)"
                    wire:key="goal-{{ $goal->id }}"
                />
            @endforeach
        </div>
    @empty
        <x-card tone="dashed" padding="18" class="max-w-[520px]">
            <p class="text-[14px] leading-[1.6] text-(--mut)">
                Aucun objectif ouvert. Poser un objectif est la façon la plus simple de faire remonter une jauge de saison.
            </p>
            <x-btn variant="secondary" class="mt-3.5 self-start" wire:click="openCreateModal">Poser un objectif</x-btn>
        </x-card>
    @endforelse

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
                <textarea wire:model="description" rows="3" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createGoal">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
