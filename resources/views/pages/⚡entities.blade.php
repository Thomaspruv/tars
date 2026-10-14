<?php

use App\Models\Entity;
use App\Support\Entity\EntityDetailFields;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Entités')] class extends Component
{
    /** Une échéance « proche » est à moins de trente jours. */
    private const int URGENT_DAYS = 30;

    #[Url]
    public ?string $typeFilter = null;

    public bool $showCreateModal = false;

    public string $name = '';

    public string $type = 'company';

    public string $notes = '';

    /** @var array<string, mixed> */
    public array $details = [];

    #[Computed]
    public function entities(): Collection
    {
        return Entity::where('status', 'active')
            ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter))
            ->with([
                'tasks' => fn ($query) => $query->open(),
                'goals',
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * Deux groupes seulement : ce qui arrive dans les trente jours, et le
     * reste. Le tri se fait par urgence d'échéance, pas par nom — c'est la
     * date qu'on vient chercher ici.
     *
     * @return array<string, \Illuminate\Support\Collection<int, Entity>>
     */
    #[Computed]
    public function groupedEntities(): array
    {
        $sorted = $this->entities->sortBy(
            fn (Entity $entity): string => $this->nextDueDateFor($entity)?->toDateString() ?? '9999-12-31'
        );

        $groups = [
            'Échéance proche' => $sorted->filter(fn (Entity $entity): bool => $this->isUrgent($entity))->values(),
            "Rien d'imminent" => $sorted->reject(fn (Entity $entity): bool => $this->isUrgent($entity))->values(),
        ];

        return array_filter($groups, fn (\Illuminate\Support\Collection $entities): bool => $entities->isNotEmpty());
    }

    public function isUrgent(Entity $entity): bool
    {
        $due = $this->nextDueDateFor($entity);

        return $due !== null && $due->lte(today()->addDays(self::URGENT_DAYS));
    }

    #[Computed]
    public function urgentCount(): int
    {
        return $this->entities->filter(fn (Entity $entity): bool => $this->isUrgent($entity))->count();
    }

    /**
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    #[Computed]
    public function detailFields(): array
    {
        return EntityDetailFields::forType($this->type);
    }

    public function updatedType(): void
    {
        $this->details = [];
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
        $this->reset(['name', 'notes', 'details']);
        $this->type = 'company';
        $this->showCreateModal = true;
    }

    public function createEntity(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:company,property,vehicle,other'],
            'notes' => ['nullable', 'string'],
            ...EntityDetailFields::validationRules($this->type, 'details'),
        ]);

        Entity::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'notes' => $validated['notes'],
            'details' => array_filter($validated['details'] ?? [], fn ($value): bool => $value !== null && $value !== ''),
        ]);

        $this->showCreateModal = false;
        unset($this->entities);
    }
};
?>

<div>
    <x-screen-header
        :kicker="$this->entities->count().' '.Str::plural('entité', $this->entities->count()).' · '.$this->urgentCount.' '.Str::plural('échéance', $this->urgentCount).' dans les 30 jours'"
        title="Entités"
        class="mb-5"
    >
        <x-slot:actions>
            @php
                $typeLabels = ['company' => 'Sociétés', 'property' => 'Biens', 'vehicle' => 'Véhicules', 'other' => 'Autres'];
            @endphp
            <button
                type="button"
                wire:click="$set('typeFilter', null)"
                class="rounded-full px-3.5 py-2 text-[13px] {{ $typeFilter === null ? 'bg-(--acbg) font-semibold text-(--ac)' : 'bg-(--surf) text-(--mut)' }}"
            >Toutes</button>
            @foreach ($typeLabels as $value => $label)
                <button
                    type="button"
                    wire:click="$set('typeFilter', '{{ $value }}')"
                    class="rounded-full px-3.5 py-2 text-[13px] {{ $typeFilter === $value ? 'bg-(--acbg) font-semibold text-(--ac)' : 'bg-(--surf) text-(--mut)' }}"
                >{{ $label }}</button>
            @endforeach
            <x-btn variant="secondary" wire:click="openCreateModal">Nouvelle</x-btn>
        </x-slot:actions>
    </x-screen-header>

    @forelse ($this->groupedEntities as $label => $entities)
        @php $isUrgentGroup = $label === 'Échéance proche'; @endphp

        <div class="mb-2.5 flex items-center gap-2.5" wire:key="group-{{ Str::slug($label) }}">
            <span class="text-[11px] tracking-[.12em] uppercase {{ $isUrgentGroup ? 'text-(--warn)' : 'text-(--mut)' }}">{{ $label }}</span>
            <span class="text-[12px] text-(--mut)">{{ $entities->count() }}</span>
        </div>

        <x-card padding="0" class="mb-6 overflow-hidden rounded-[16px]">
            @foreach ($entities as $entity)
                <x-entity-row
                    :entity="$entity"
                    :open-tasks-count="$entity->tasks->count()"
                    :next-due-date="$this->nextDueDateFor($entity)"
                    :linked-goal="$entity->goals->first()"
                    :urgent="$isUrgentGroup"
                    wire:key="entity-{{ $entity->id }}"
                    @class(['border-t border-(--bd)' => ! $loop->first])
                />
            @endforeach
        </x-card>
    @empty
        <p class="py-10 text-[15px] text-(--mut)">Aucune entité pour l'instant.</p>
    @endforelse

    <flux:modal wire:model.self="showCreateModal" class="md:w-[420px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvelle entité</flux:heading>

            <div>
                <label class="text-[12px] text-(--mut)">Nom</label>
                <input type="text" wire:model="name" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                @error('name') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Type</label>
                <select wire:model.live="type" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none">
                    <option value="company">Entreprise</option>
                    <option value="property">Bien</option>
                    <option value="vehicle">Véhicule</option>
                    <option value="other">Autre</option>
                </select>
            </div>

            <x-entity-detail-fields :fields="$this->detailFields" prefix="details" />

            <div>
                <label class="text-[12px] text-(--mut)">Notes (optionnel)</label>
                <textarea wire:model="notes" rows="3" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createEntity">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
