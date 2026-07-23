<?php

use App\Models\LifeArea;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Réglages')] class extends Component
{
    private const array PRESET_COLORS = [
        '#53D6E8', '#9D8CF5', '#4ADE80', '#FBBF24', '#F87171', '#60A5FA', '#F472B6', '#8A93A8',
    ];

    public bool $showFormModal = false;

    public ?int $editingLifeAreaId = null;

    public string $name = '';

    public string $color = '#53D6E8';

    #[Computed]
    public function lifeAreas(): Collection
    {
        return LifeArea::orderBy('position')->get();
    }

    public function presetColors(): array
    {
        return self::PRESET_COLORS;
    }

    public function openCreateModal(): void
    {
        $this->reset(['name', 'editingLifeAreaId']);
        $this->color = self::PRESET_COLORS[0];
        $this->showFormModal = true;
    }

    public function openEditModal(int $lifeAreaId): void
    {
        $lifeArea = LifeArea::findOrFail($lifeAreaId);

        $this->editingLifeAreaId = $lifeArea->id;
        $this->name = $lifeArea->name;
        $this->color = $lifeArea->color;
        $this->showFormModal = true;
    }

    public function save(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', 'string'],
        ]);

        if ($this->editingLifeAreaId) {
            LifeArea::findOrFail($this->editingLifeAreaId)->update($validated);
        } else {
            LifeArea::create([...$validated, 'position' => $this->lifeAreas->count()]);
        }

        $this->showFormModal = false;
        unset($this->lifeAreas);
    }

    public function delete(int $lifeAreaId): void
    {
        LifeArea::findOrFail($lifeAreaId)->delete();

        unset($this->lifeAreas);
    }
};
?>

<div>
    <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Réglages</h1>

    <div class="mt-8">
        <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold text-(--tx)">Domaines de vie</h2>
            <x-btn variant="primary" wire:click="openCreateModal">+ Nouveau domaine</x-btn>
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
            @forelse ($this->lifeAreas as $lifeArea)
                <div class="flex items-center gap-2 rounded-full border border-(--bd) bg-(--surf) px-3 py-1.5" wire:key="area-{{ $lifeArea->id }}">
                    <span class="size-2.5 rounded-[3px]" style="background: {{ $lifeArea->color }}"></span>
                    <span class="text-sm text-(--tx)">{{ $lifeArea->name }}</span>
                    <button type="button" wire:click="openEditModal({{ $lifeArea->id }})" class="text-(--mut) hover:text-(--tx)">
                        <flux:icon name="pencil" class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        wire:click="delete({{ $lifeArea->id }})"
                        wire:confirm="Supprimer ce domaine de vie ?"
                        class="text-(--mut) hover:text-(--dgr)"
                    >
                        <flux:icon name="x-mark" class="size-3.5" />
                    </button>
                </div>
            @empty
                <p class="text-sm text-(--mut)">Aucun domaine de vie pour l'instant.</p>
            @endforelse
        </div>
    </div>

    <flux:modal wire:model.self="showFormModal" class="md:w-[380px]">
        <div class="space-y-5">
            <flux:heading size="lg">{{ $editingLifeAreaId ? 'Modifier le domaine' : 'Nouveau domaine' }}</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Nom</label>
                <input type="text" wire:model="name" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
                @error('name') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Couleur</label>
                <div class="mt-2 flex gap-2">
                    @foreach ($this->presetColors() as $preset)
                        <button
                            type="button"
                            wire:click="$set('color', '{{ $preset }}')"
                            class="size-7 rounded-full {{ $color === $preset ? 'ring-2 ring-(--tx) ring-offset-2 ring-offset-(--surf)' : '' }}"
                            style="background: {{ $preset }}"
                            aria-label="{{ $preset }}"
                        ></button>
                    @endforeach
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showFormModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="save">Enregistrer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
