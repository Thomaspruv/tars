<?php

use App\Models\Entity;
use App\Models\LifeArea;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Entités')] class extends Component
{
    public bool $showCreateModal = false;

    public string $name = '';

    public string $type = 'company';

    public string $context = 'perso';

    public ?int $lifeAreaId = null;

    public string $notes = '';

    #[Computed]
    public function entities(): Collection
    {
        return Entity::where('status', 'active')
            ->with([
                'tasks' => fn ($query) => $query->open(),
                'goals',
            ])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function allLifeAreas(): Collection
    {
        return LifeArea::orderBy('position')->get();
    }

    public function nextDueDateFor(Entity $entity)
    {
        return $entity->tasks
            ->pluck('due_date')
            ->filter()
            ->sort()
            ->first();
    }

    public function openCreateModal(): void
    {
        $this->reset(['name', 'notes']);
        $this->type = 'company';
        $this->context = 'perso';
        $this->lifeAreaId = $this->allLifeAreas->first()?->id;
        $this->showCreateModal = true;
    }

    public function createEntity(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:company,property,vehicle,other'],
            'context' => ['required', 'in:perso,pro'],
            'lifeAreaId' => ['required', 'exists:life_areas,id'],
            'notes' => ['nullable', 'string'],
        ]);

        Entity::create([
            'life_area_id' => $validated['lifeAreaId'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'context' => $validated['context'],
            'notes' => $validated['notes'],
        ]);

        $this->showCreateModal = false;
        unset($this->entities);
    }
};
?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Entités</h1>
            <p class="mt-1 text-sm text-(--mut)">Elles se maintiennent, elles ne progressent pas.</p>
        </div>
        <x-btn variant="primary" wire:click="openCreateModal">+ Nouvelle entité</x-btn>
    </div>

    <div class="mt-8 grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))">
        @forelse ($this->entities as $entity)
            <x-card-entity
                :entity="$entity"
                :open-tasks-count="$entity->tasks->count()"
                :next-due-date="$this->nextDueDateFor($entity)"
                :linked-goal="$entity->goals->first()"
                wire:key="entity-{{ $entity->id }}"
            />
        @empty
            <p class="col-span-full p-8 text-center text-sm text-(--mut)">Aucune entité pour l'instant.</p>
        @endforelse
    </div>

    <flux:modal wire:model.self="showCreateModal" class="md:w-[420px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvelle entité</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Nom</label>
                <input type="text" wire:model="name" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
                @error('name') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs text-(--mut)">Type</label>
                    <select wire:model="type" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)">
                        <option value="company">Entreprise</option>
                        <option value="property">Bien</option>
                        <option value="vehicle">Véhicule</option>
                        <option value="other">Autre</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs text-(--mut)">Contexte</label>
                    <select wire:model="context" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)">
                        <option value="perso">Perso</option>
                        <option value="pro">Pro</option>
                    </select>
                </div>
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
                <label class="text-xs text-(--mut)">Notes (optionnel)</label>
                <textarea wire:model="notes" rows="3" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createEntity">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
