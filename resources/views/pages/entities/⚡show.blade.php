<?php

use App\Models\Entity;
use App\Models\Task;
use App\Support\Brain\BrainSettings;
use App\Support\Entity\EntityDetailFields;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Entité')] class extends Component
{
    public Entity $entity;

    public bool $showEditModal = false;

    public string $editName = '';

    public string $editNotes = '';

    /** @var array<string, mixed> */
    public array $editDetails = [];

    public string $newNoteContent = '';

    public function mount(): void
    {
        $this->entity->load(['goals.milestones', 'lifeArea']);
    }

    #[Computed]
    public function relations(): Collection
    {
        return $this->entity->relations()->with('relatedEntity')->get();
    }

    #[Computed]
    public function relatedFrom(): Collection
    {
        return $this->entity->relatedFrom()->with('entity')->get();
    }

    #[Computed]
    public function openTasks(): Collection
    {
        return $this->entity->tasks()->open()->orderBy('due_date')->get();
    }

    #[Computed]
    public function recurringTasks(): Collection
    {
        return $this->entity->tasks()->whereNotNull('recurrence')->get();
    }

    #[Computed]
    public function notes(): Collection
    {
        return $this->entity->notes()->latest()->get();
    }

    #[Computed]
    public function brainConfigured(): bool
    {
        return app(BrainSettings::class)->isConfigured();
    }

    #[Computed]
    public function brainDocuments(): Collection
    {
        return $this->entity->brainDocuments()->latest('mtime')->get();
    }

    /**
     * @return list<array{key: string, label: string, type: string, options?: array<string, string>}>
     */
    #[Computed]
    public function detailFields(): array
    {
        return EntityDetailFields::forType($this->entity->type);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    #[Computed]
    public function filledDetails(): array
    {
        return EntityDetailFields::filledPairs($this->entity->type, $this->entity->details ?? []);
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        unset($this->openTasks, $this->recurringTasks);
    }

    public function openEditModal(): void
    {
        $this->editName = $this->entity->name;
        $this->editNotes = (string) $this->entity->notes;
        $this->editDetails = $this->entity->details ?? [];
        $this->showEditModal = true;
    }

    public function saveEdit(): void
    {
        $validated = $this->validate([
            'editName' => ['required', 'string', 'max:255'],
            'editNotes' => ['nullable', 'string'],
            ...EntityDetailFields::validationRules($this->entity->type, 'editDetails'),
        ]);

        $this->entity->update([
            'name' => $validated['editName'],
            'notes' => $validated['editNotes'],
            'details' => array_filter($validated['editDetails'] ?? [], fn ($value): bool => $value !== null && $value !== ''),
        ]);

        $this->showEditModal = false;
    }

    public function archive()
    {
        $this->entity->update(['status' => 'archived']);

        return $this->redirect(route('entities.index'), navigate: true);
    }

    public function addNote(): void
    {
        $this->validate(['newNoteContent' => ['required', 'string']]);

        $this->entity->notes()->create([
            'content' => $this->newNoteContent,
            'source' => 'user',
        ]);

        $this->newNoteContent = '';
        unset($this->notes);
    }
};
?>

<div>
    <a href="{{ route('entities.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm text-(--mut) hover:text-(--tx)">
        <flux:icon name="arrow-left" class="size-4" /> Entités
    </a>

    <div class="mt-4 flex items-start justify-between gap-4">
        <div>
            <p class="text-xs text-(--mut)">{{ $entity->lifeArea?->name }}</p>
            <h1 class="mt-1 text-[28px] font-bold tracking-[-0.02em] text-(--tx)">{{ $entity->name }}</h1>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <x-btn variant="secondary" wire:click="openEditModal">Modifier</x-btn>
            <x-btn variant="danger" wire:click="archive" wire:confirm="Archiver cette entité ?">Archiver</x-btn>
        </div>
    </div>

    <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-2">
        <div class="space-y-8">
            @if ($this->filledDetails !== [])
                <div>
                    <h2 class="text-base font-semibold text-(--tx)">Informations</h2>
                    <dl class="mt-3 space-y-2 rounded-[14px] border border-(--bd) bg-(--surf) p-4 text-sm">
                        @foreach ($this->filledDetails as $pair)
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-(--mut)">{{ $pair['label'] }}</dt>
                                <dd class="text-right text-(--tx)">{{ $pair['value'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endif

            @if ($this->recurringTasks->isNotEmpty())
                <div>
                    <h2 class="text-base font-semibold text-(--tx)">Échéances récurrentes</h2>
                    <div class="mt-3 divide-y divide-(--bd) rounded-[14px] border border-(--bd) bg-(--surf)">
                        @foreach ($this->recurringTasks as $task)
                            <x-task-row :task="$task" wire:key="recurring-{{ $task->id }}" />
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <h2 class="text-base font-semibold text-(--tx)">Tâches ouvertes</h2>
                <div class="mt-3 divide-y divide-(--bd) rounded-[14px] border border-(--bd) bg-(--surf)">
                    @forelse ($this->openTasks as $task)
                        <x-task-row :task="$task" wire:key="task-{{ $task->id }}" />
                    @empty
                        <p class="p-5 text-center text-sm text-(--mut)">Aucune tâche ouverte.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="space-y-8">
            @if ($entity->goals->isNotEmpty())
                <div>
                    <h2 class="text-base font-semibold text-(--tx)">Objectif rattaché</h2>
                    <div class="mt-3 space-y-3">
                        @foreach ($entity->goals as $goal)
                            <x-card-goal :goal="$goal" wire:key="goal-{{ $goal->id }}" />
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->relations->isNotEmpty() || $this->relatedFrom->isNotEmpty())
                <div>
                    <h2 class="text-base font-semibold text-(--tx)">Relations</h2>
                    <div class="mt-3 space-y-2 text-sm">
                        @foreach ($this->relations as $relation)
                            <div class="flex items-center justify-between rounded-[10px] border border-(--bd) bg-(--surf) px-3 py-2" wire:key="relation-{{ $relation->id }}">
                                <span class="font-mono text-[10.5px] text-(--mut)">{{ $relation->relation_type->label() }}</span>
                                <a href="{{ route('entities.show', $relation->relatedEntity) }}" wire:navigate class="text-(--ac)">{{ $relation->relatedEntity->name }}</a>
                            </div>
                        @endforeach
                        @foreach ($this->relatedFrom as $relation)
                            <div class="flex items-center justify-between rounded-[10px] border border-(--bd) bg-(--surf) px-3 py-2" wire:key="related-from-{{ $relation->id }}">
                                <a href="{{ route('entities.show', $relation->entity) }}" wire:navigate class="text-(--ac)">{{ $relation->entity->name }}</a>
                                <span class="font-mono text-[10.5px] text-(--mut)">{{ $relation->relation_type->label() }} (entrant)</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div>
                <h2 class="text-base font-semibold text-(--tx)">Notes</h2>
                <div class="mt-3 space-y-2">
                    @forelse ($this->notes as $note)
                        <div class="rounded-[10px] border border-(--bd) bg-(--surf) p-3" wire:key="note-{{ $note->id }}">
                            @if ($note->source === \App\Enums\NoteSource::Agent)
                                <span class="font-mono text-[10px] font-semibold text-(--ai)">◈ AGENT</span>
                            @endif
                            <p class="mt-1 text-sm text-(--tx)">{{ $note->content }}</p>
                            <p class="mt-1 font-mono text-[10.5px] text-(--mut)">{{ $note->created_at->translatedFormat('d M · H:i') }}</p>
                        </div>
                    @empty
                        <p class="rounded-[14px] border border-(--bd) bg-(--surf) p-5 text-center text-sm text-(--mut)">Aucune note.</p>
                    @endforelse
                </div>
                <form wire:submit="addNote" class="mt-2 flex gap-2">
                    <input
                        type="text"
                        wire:model="newNoteContent"
                        placeholder="Ajouter une note…"
                        class="flex-1 rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-1.5 text-sm text-(--tx)"
                    />
                    <x-btn variant="secondary" type="submit" class="!px-3 !py-1.5 text-xs">Ajouter</x-btn>
                </form>
            </div>

            @if ($this->brainConfigured)
                <x-brain-notes :documents="$this->brainDocuments" />
            @endif
        </div>
    </div>

    <flux:modal wire:model.self="showEditModal" class="md:w-[420px]">
        <div class="space-y-5" wire:loading.class="opacity-50" wire:target="saveEdit">
            <flux:heading size="lg">Modifier l'entité</flux:heading>

            <div>
                <label class="text-xs text-(--mut)">Nom</label>
                <input type="text" wire:model="editName" wire:loading.attr="disabled" wire:target="saveEdit" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)" />
                @error('editName') <p class="mt-1 text-xs text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-xs text-(--mut)">Notes</label>
                <textarea wire:model="editNotes" wire:loading.attr="disabled" wire:target="saveEdit" rows="3" class="mt-1 w-full rounded-[8px] border border-(--bd2) bg-(--in) px-3 py-2 text-sm text-(--tx)"></textarea>
            </div>

            <x-entity-detail-fields :fields="$this->detailFields" prefix="editDetails" target="saveEdit" />

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
