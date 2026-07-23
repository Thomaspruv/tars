<?php

use App\Enums\GoalStatus;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\LifeArea;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Objectifs')] class extends Component
{
    public bool $showCreateModal = false;

    public string $title = '';

    public string $description = '';

    public ?int $lifeAreaId = null;

    public ?int $entityId = null;

    public ?string $targetDate = null;

    #[Computed]
    public function lifeAreas(): Collection
    {
        return LifeArea::with([
            'goals' => fn ($query) => $query->orderBy('position')->with([
                'milestones',
                'lifeArea',
                'tasks' => fn ($query) => $query->where('status', 'done')
                    ->where('completed_at', '>=', now()->subWeeks(6))
                    ->select('id', 'goal_id', 'completed_at'),
            ]),
        ])->orderBy('position')->get();
    }

    #[Computed]
    public function allLifeAreas(): Collection
    {
        return LifeArea::orderBy('position')->get();
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
        $this->lifeAreaId = $this->allLifeAreas->first()?->id;
        $this->showCreateModal = true;
    }

    public function createGoal(): void
    {
        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'lifeAreaId' => ['required', 'exists:life_areas,id'],
            'entityId' => ['nullable', 'exists:entities,id'],
            'targetDate' => ['nullable', 'date'],
        ]);

        Goal::create([
            'life_area_id' => $validated['lifeAreaId'],
            'entity_id' => $validated['entityId'],
            'title' => $validated['title'],
            'description' => $validated['description'],
            'target_date' => $validated['targetDate'],
        ]);

        $this->showCreateModal = false;
        unset($this->lifeAreas);
    }
};
?>

<div>
    <div class="flex items-center justify-between">
        <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Objectifs</h1>
        <x-btn variant="primary" wire:click="openCreateModal">+ Nouvel objectif</x-btn>
    </div>

    <div class="mt-8 space-y-10">
        @forelse ($this->lifeAreas as $lifeArea)
            @if ($lifeArea->goals->isNotEmpty())
                <div wire:key="area-{{ $lifeArea->id }}">
                    <div class="flex items-center gap-2">
                        <span class="size-2.5 rounded-[3px]" style="background: {{ $lifeArea->color }}"></span>
                        <h2 class="text-base font-semibold text-(--tx)">{{ $lifeArea->name }}</h2>
                    </div>

                    <div class="mt-4 grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(300px, 1fr))">
                        @foreach ($lifeArea->goals as $goal)
                            <x-card-goal
                                :goal="$goal"
                                :weekly-activity="$this->weeklyActivityFor($goal)"
                                :stagnant="$this->isStagnant($goal)"
                                wire:key="goal-{{ $goal->id }}"
                            />
                        @endforeach
                    </div>
                </div>
            @endif
        @empty
        @endforelse

        @if ($this->lifeAreas->every(fn ($lifeArea) => $lifeArea->goals->isEmpty()))
            <p class="p-8 text-center text-sm text-(--mut)">Aucun objectif pour l'instant.</p>
        @endif
    </div>

    <flux:modal wire:model.self="showCreateModal" class="md:w-[420px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvel objectif</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Titre</label>
                <input type="text" wire:model="title" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
                @error('title') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Domaine de vie</label>
                <select wire:model="lifeAreaId" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)">
                    @foreach ($this->allLifeAreas as $lifeArea)
                        <option value="{{ $lifeArea->id }}">{{ $lifeArea->name }}</option>
                    @endforeach
                </select>
                @error('lifeAreaId') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Entité liée (optionnel)</label>
                <select wire:model="entityId" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)">
                    <option value="">—</option>
                    @foreach ($this->allEntities as $entity)
                        <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs text-(--mut)">Échéance (optionnel)</label>
                <input type="date" wire:model="targetDate" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 font-mono text-sm text-(--tx)" />
            </div>

            <div>
                <label class="text-xs text-(--mut)">Description (optionnel)</label>
                <textarea wire:model="description" rows="3" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createGoal">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
