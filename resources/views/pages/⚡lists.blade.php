<?php

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Entity;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Listes')] class extends Component
{
    public bool $showCreateModal = false;

    public string $name = '';

    public ?int $entityId = null;

    public array $newItemContent = [];

    #[Computed]
    public function lists(): Collection
    {
        return Checklist::with(['items' => fn ($query) => $query->orderBy('position'), 'entity'])
            ->orderByDesc('is_pinned')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function allEntities(): Collection
    {
        return Entity::orderBy('name')->get();
    }

    public function openCreateModal(): void
    {
        $this->reset(['name', 'entityId']);
        $this->showCreateModal = true;
    }

    public function createList(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'entityId' => ['nullable', 'exists:entities,id'],
        ]);

        Checklist::create(['name' => $validated['name'], 'entity_id' => $validated['entityId']]);

        $this->showCreateModal = false;
        unset($this->lists);
    }

    public function togglePin(int $listId): void
    {
        $list = Checklist::findOrFail($listId);
        $list->update(['is_pinned' => ! $list->is_pinned]);

        unset($this->lists);
    }

    public function deleteList(int $listId): void
    {
        Checklist::findOrFail($listId)->delete();

        unset($this->lists);
    }

    public function addItem(int $listId): void
    {
        $content = trim($this->newItemContent[$listId] ?? '');

        if ($content === '') {
            return;
        }

        ChecklistItem::create([
            'list_id' => $listId,
            'content' => $content,
            'position' => ChecklistItem::where('list_id', $listId)->count(),
        ]);

        $this->newItemContent[$listId] = '';

        unset($this->lists);
    }

    public function toggleItem(int $itemId): void
    {
        $item = ChecklistItem::findOrFail($itemId);
        $item->update(['checked_at' => $item->checked_at ? null : now()]);

        unset($this->lists);
    }

    public function deleteItem(int $itemId): void
    {
        ChecklistItem::findOrFail($itemId)->delete();

        unset($this->lists);
    }
};
?>

<div>
    <div class="flex items-center justify-between">
        <h1 class="text-[28px] font-bold tracking-[-0.02em] text-(--tx)">Listes</h1>
        <x-btn variant="primary" wire:click="openCreateModal">+ Nouvelle liste</x-btn>
    </div>

    <div class="mt-8 grid gap-4" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))">
        @forelse ($this->lists as $list)
            <div class="rounded-[14px] border border-(--bd) bg-(--surf) p-5" wire:key="list-{{ $list->id }}">
                <div class="flex items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-[15px] font-semibold text-(--tx)">{{ $list->name }}</h2>
                        @if ($list->entity)
                            <x-badge-entity :entity="$list->entity" />
                        @endif
                    </div>
                    <div class="flex items-center gap-1">
                        <button
                            type="button"
                            wire:click="togglePin({{ $list->id }})"
                            aria-label="{{ $list->is_pinned ? 'Désépingler' : 'Épingler' }}"
                            class="rounded-[8px] p-1.5 {{ $list->is_pinned ? 'text-(--ac)' : 'text-(--mut) hover:text-(--tx)' }}"
                        >
                            <flux:icon name="map-pin" class="size-4" />
                        </button>
                        <button
                            type="button"
                            wire:click="deleteList({{ $list->id }})"
                            wire:confirm="Supprimer cette liste ?"
                            aria-label="Supprimer"
                            class="rounded-[8px] p-1.5 text-(--mut) hover:text-(--dgr)"
                        >
                            <flux:icon name="trash" class="size-4" />
                        </button>
                    </div>
                </div>

                <div class="mt-3 space-y-0.5">
                    @foreach ($list->items as $item)
                        <div class="group flex items-center gap-2.5 rounded-[9px] px-1 py-1.5" wire:key="item-{{ $item->id }}">
                            <input
                                type="checkbox"
                                wire:click="toggleItem({{ $item->id }})"
                                @checked($item->checked_at)
                                class="size-[16px] shrink-0 rounded-full border-(--bd2) text-(--ac) focus:ring-0"
                            />
                            <span class="flex-1 text-sm {{ $item->checked_at ? 'text-(--mut) line-through opacity-45' : 'text-(--tx)' }}">
                                {{ $item->content }}
                            </span>
                            <button
                                type="button"
                                wire:click="deleteItem({{ $item->id }})"
                                class="shrink-0 text-(--mut) opacity-0 group-hover:opacity-100 hover:text-(--dgr)"
                            >
                                <flux:icon name="x-mark" class="size-3.5" />
                            </button>
                        </div>
                    @endforeach
                </div>

                <form wire:submit="addItem({{ $list->id }})" class="mt-2 flex gap-2">
                    <input
                        type="text"
                        wire:model="newItemContent.{{ $list->id }}"
                        placeholder="Ajouter un élément…"
                        class="flex-1 rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 text-sm text-(--tx)"
                    />
                </form>
            </div>
        @empty
            <p class="col-span-full p-8 text-center text-sm text-(--mut)">Aucune liste pour l'instant.</p>
        @endforelse
    </div>

    <flux:modal wire:model.self="showCreateModal" class="md:w-[380px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvelle liste</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Nom</label>
                <input type="text" wire:model="name" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
                @error('name') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
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

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createList">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
