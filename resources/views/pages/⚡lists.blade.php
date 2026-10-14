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
    public function pinnedCount(): int
    {
        return $this->lists->where('is_pinned', true)->count();
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
    <x-screen-header
        :kicker="$this->lists->count().' '.Str::plural('liste', $this->lists->count()).' · '.$this->pinnedCount.' '.Str::plural('épinglée', $this->pinnedCount)"
        title="Listes"
        class="mb-5"
    >
        <x-slot:actions>
            <x-btn variant="secondary" wire:click="openCreateModal">Nouvelle liste</x-btn>
        </x-slot:actions>
    </x-screen-header>

    {{-- Le seul écran en colonnes de cartes, parce que les listes sont
         vraiment des objets séparés. Cases *rondes* ici, carrées pour les
         tâches : la distinction est portée par la forme, pas par la couleur. --}}
    <div class="grid items-start gap-4" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr))">
        @forelse ($this->lists as $list)
            @php
                // Au-delà, la carte de liste devient une colonne interminable.
                $visibleItems = 6;
                $openItems = $list->items->whereNull('checked_at');
                $doneCount = $list->items->count() - $openItems->count();
            @endphp

            <x-card padding="20" class="group/list" wire:key="list-{{ $list->id }}">
                <div class="mb-4 flex items-center gap-2.5">
                    <h2 class="font-display min-w-0 truncate text-[18px] font-semibold text-(--tx)">{{ $list->name }}</h2>

                    @if ($list->is_pinned)
                        <x-badge type="pill" tone="mine" class="!text-[11px] shrink-0">épinglée</x-badge>
                    @endif

                    @if ($openItems->isNotEmpty())
                        <span class="ml-auto shrink-0 text-[12px] text-(--mut)">{{ $openItems->count() }}</span>
                    @endif

                    <button
                        type="button"
                        wire:click="togglePin({{ $list->id }})"
                        class="press {{ $openItems->isNotEmpty() ? '' : 'ml-auto' }} shrink-0 text-[12px] text-(--mut) hover:text-(--tx)"
                    >{{ $list->is_pinned ? 'Détacher' : 'Épingler' }}</button>

                    <button
                        type="button"
                        wire:click="deleteList({{ $list->id }})"
                        wire:confirm="Supprimer cette liste ?"
                        aria-label="Supprimer la liste"
                        class="shrink-0 text-[12px] text-(--mut) opacity-0 group-hover/list:opacity-100 hover:text-(--dgr)"
                    >✕</button>
                </div>

                {{-- Une liste vide affiche une bonne nouvelle, pas un vide. --}}
                @if ($list->items->isNotEmpty() && $openItems->isEmpty())
                    <p class="mb-4 text-[14px] leading-[1.6] text-(--ai)">Vide — tout est fait.</p>
                @else
                    <div class="flex flex-col gap-[13px]">
                        @foreach ($openItems->take($visibleItems) as $item)
                            <div class="group flex items-center gap-3 text-[14px]" wire:key="item-{{ $item->id }}">
                                <x-checkbox
                                    shape="round"
                                    size="18"
                                    wire:click="toggleItem({{ $item->id }})"
                                    target="toggleItem({{ $item->id }})"
                                />
                                <span class="min-w-0 flex-1 text-(--tx)">{{ $item->content }}</span>
                                <button
                                    type="button"
                                    wire:click="deleteItem({{ $item->id }})"
                                    aria-label="Supprimer l'article"
                                    class="shrink-0 text-(--mut) opacity-0 group-hover:opacity-100 hover:text-(--dgr)"
                                >✕</button>
                            </div>
                        @endforeach

                        @if ($openItems->count() > $visibleItems || $doneCount > 0)
                            <p class="pt-0.5 text-[13px] text-(--mut)">
                                @if ($openItems->count() > $visibleItems)
                                    + {{ $openItems->count() - $visibleItems }} autres
                                @endif
                                @if ($openItems->count() > $visibleItems && $doneCount > 0) · @endif
                                @if ($doneCount > 0)
                                    {{ $doneCount }} {{ Str::plural('déjà fait', $doneCount) }}
                                @endif
                            </p>
                        @endif
                    </div>
                @endif

                <form wire:submit="addItem({{ $list->id }})" class="mt-3 flex items-center gap-3 border-t border-(--bd) pt-3">
                    <span aria-hidden="true" class="w-[18px] shrink-0 text-center text-(--mut)">+</span>
                    <input
                        type="text"
                        wire:model="newItemContent.{{ $list->id }}"
                        placeholder="Ajouter un article"
                        class="w-full bg-transparent text-[14px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                    />
                </form>
            </x-card>
        @empty
            <p class="col-span-full py-10 text-[15px] text-(--mut)">Aucune liste pour l'instant.</p>
        @endforelse
    </div>

    <flux:modal wire:model.self="showCreateModal" class="md:w-[380px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvelle liste</flux:heading>

            <div>
                <label class="text-[12px] text-(--mut)">Nom</label>
                <input type="text" wire:model="name" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                @error('name') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
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

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createList">Créer</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
