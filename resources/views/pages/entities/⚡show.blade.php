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
        $this->entity->load(['goals.milestones']);
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
        return $this->entity->tasks()->open()->topLevel()->with(['goal', 'subtasks.goal', 'subtasks.entity'])->orderBy('due_date')->get();
    }

    #[Computed]
    public function recurringTasks(): Collection
    {
        return $this->entity->tasks()->whereNotNull('recurrence')->topLevel()->with(['goal', 'subtasks.goal', 'subtasks.entity'])->get();
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

    public function createSubtask(int $parentTaskId, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        Task::findOrFail($parentTaskId)->subtasks()->create(['title' => $title, 'status' => 'todo']);

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

@php
    $typeLabels = ['company' => 'Société', 'property' => 'Bien', 'vehicle' => 'Véhicule', 'other' => 'Autre'];
    $entityType = $entity->type instanceof \App\Enums\EntityType ? $entity->type->value : $entity->type;
    $initials = collect(preg_split('/[\s\-]+/', (string) $entity->name))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
    $linkedGoal = $entity->goals->first();
@endphp

{{-- Même gabarit que la fiche objectif, contenu différent : là où l'objectif a
     des jalons et un rythme, l'entité a des échéances qui reviennent et des
     champs. Aucune barre de progression nulle part. --}}
<div>
    <div class="mb-[26px] flex max-w-[1200px] flex-wrap items-center gap-3.5">
        <a href="{{ route('entities.index') }}" wire:navigate class="text-[13px] text-(--mut) hover:text-(--tx)">← Entités</a>

        <div class="ml-auto flex flex-wrap gap-2">
            <x-btn variant="secondary" wire:click="openEditModal">Modifier</x-btn>
            <x-btn variant="danger" wire:click="archive" wire:confirm="Archiver cette entité ?">Archiver</x-btn>
        </div>
    </div>

    <div class="grid max-w-[1200px] grid-cols-1 gap-8 xl:grid-cols-[1fr_320px] xl:gap-12">
        <div class="max-w-[620px]">
            <div class="mb-[18px] flex items-center gap-4">
                <span
                    class="font-display inline-flex size-[52px] shrink-0 items-center justify-center rounded-[14px] bg-(--acbg) text-[18px] font-bold text-(--ac)"
                    aria-hidden="true"
                >{{ $initials }}</span>
                <div class="min-w-0">
                    <h1 class="font-display text-[34px] leading-[1.1] font-bold tracking-[-.03em] text-(--tx)">{{ $entity->name }}</h1>
                    <p class="mt-1 text-[13px] text-(--mut)">
                        {{ $typeLabels[$entityType] ?? ucfirst((string) $entityType) }} · créée en {{ $entity->created_at->translatedFormat('F Y') }}
                    </p>
                </div>
            </div>

            @if ($this->filledDetails !== [] || $linkedGoal)
                <dl class="mb-8 grid grid-cols-1 gap-x-8 sm:grid-cols-2">
                    @foreach ($this->filledDetails as $pair)
                        <div class="border-b border-(--bd) py-3">
                            <dt class="mb-1 text-[12px] text-(--mut)">{{ $pair['label'] }}</dt>
                            <dd class="text-[15px] text-(--tx)">{{ $pair['value'] }}</dd>
                        </div>
                    @endforeach

                    @if ($linkedGoal)
                        <div class="border-b border-(--bd) py-3">
                            <dt class="mb-1 text-[12px] text-(--mut)">Objectif rattaché</dt>
                            <dd class="text-[15px]">
                                <a href="{{ route('goals.show', $linkedGoal) }}" wire:navigate class="flex items-center gap-2 text-(--tx) hover:underline">
                                    <span class="size-[8px] rounded-full" style="background: var(--goal-{{ $linkedGoal->color_slot }})" aria-hidden="true"></span>
                                    {{ $linkedGoal->title }}
                                </a>
                            </dd>
                        </div>
                    @endif
                </dl>
            @endif

            @if ($this->recurringTasks->isNotEmpty())
                <div class="mb-3 flex items-baseline gap-3">
                    <h2 class="font-display text-[20px] font-semibold text-(--tx)">Échéances récurrentes</h2>
                    <span class="text-[13px] text-(--mut)">{{ $this->recurringTasks->count() }}</span>
                </div>

                <div class="mb-8 flex flex-col">
                    @foreach ($this->recurringTasks as $task)
                        @php $due = $task->due_date ?? $task->scheduled_date; @endphp
                        <div class="flex flex-wrap items-center gap-4 border-b border-(--bd) px-1 py-[13px]" wire:key="recurring-{{ $task->id }}">
                            <span class="min-w-0 flex-1 text-[15px] text-(--tx)">{{ $task->title }}</span>
                            <x-badge type="recur" :recurrence="$task->recurrence" class="ml-auto" />
                            @if ($due)
                                <time
                                    class="w-[120px] text-right text-[13px] {{ $due->lte(today()->addDays(30)) ? 'text-(--warn)' : 'text-(--mut)' }}"
                                    datetime="{{ $due->toDateString() }}"
                                >{{ $due->translatedFormat('j F') }}</time>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Les notes mélangent les miennes et les synthèses d'Hermès ;
                 seules les secondes portent le filet vert et leur date de
                 génération. --}}
            <div class="mb-3.5 flex items-baseline gap-3">
                <h2 class="font-display text-[20px] font-semibold text-(--tx)">Notes</h2>
                @php $agentNotes = $this->notes->where('source', \App\Enums\NoteSource::Agent)->count(); @endphp
                <span class="text-[13px] text-(--mut)">
                    {{ $this->notes->count() }}@if ($agentNotes > 0) · dont {{ $agentNotes }} {{ Str::plural('synthèse', $agentNotes) }} d'Hermès @endif
                </span>
            </div>

            <div class="mb-4 flex flex-col gap-3">
                @forelse ($this->notes as $note)
                    @if ($note->source === \App\Enums\NoteSource::Agent)
                        <x-agent-block padding="16" rail="3" class="!rounded-[14px]" wire:key="note-{{ $note->id }}">
                            <p class="mb-1.5 text-[12px] text-(--ai)">Synthèse d'Hermès · générée le {{ $note->created_at->translatedFormat('j F') }}</p>
                            <p class="text-[14px] leading-[1.6] text-(--tx)">{{ $note->content }}</p>
                        </x-agent-block>
                    @else
                        <x-card padding="16" class="!rounded-[14px]" wire:key="note-{{ $note->id }}">
                            <p class="mb-1.5 text-[12px] text-(--mut)">{{ $note->created_at->translatedFormat('j F') }} · ma note</p>
                            <p class="text-[14px] leading-[1.6] text-(--tx)">{{ $note->content }}</p>
                        </x-card>
                    @endif
                @empty
                    <p class="text-[14px] text-(--mut)">Aucune note.</p>
                @endforelse
            </div>

            <form wire:submit="addNote" class="flex items-center gap-3 border-t border-(--bd) pt-3">
                <span aria-hidden="true" class="w-[17px] shrink-0 text-center text-(--mut)">+</span>
                <input
                    type="text"
                    wire:model="newNoteContent"
                    placeholder="Ajouter une note"
                    class="w-full bg-transparent text-[15px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                />
            </form>
            @error('newNoteContent') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
        </div>

        <div>
            <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Tâches ouvertes</p>
            <div class="mb-[30px] flex flex-col gap-3">
                @foreach ($this->openTasks as $task)
                    @php
                        $due = $task->due_date ?? $task->scheduled_date;
                        $isOverdue = $due && $due->isPast() && ! $due->isToday();
                    @endphp
                    <div class="flex items-center gap-3 text-[14px]" wire:key="task-{{ $task->id }}">
                        <button
                            type="button"
                            wire:click="toggleTask({{ $task->id }})"
                            wire:target="toggleTask({{ $task->id }})"
                            wire:loading.attr="disabled"
                            wire:loading.class="scale-90 opacity-60"
                            aria-label="Marquer terminée"
                            class="size-[17px] shrink-0 rounded-[6px] border-2 transition-[border-color,scale] duration-(--dur-swap) ease-(--ease) active:scale-[.88] {{ $isOverdue ? 'border-(--dgr)' : 'border-(--bd3)' }}"
                        ></button>
                        <span class="min-w-0 flex-1 text-(--tx)">{{ $task->title }}</span>
                    </div>
                @endforeach

                @if ($this->openTasks->isEmpty())
                    <p class="text-[14px] text-(--mut)">Aucune tâche ouverte.</p>
                @endif
            </div>

            @if ($this->relations->isNotEmpty() || $this->relatedFrom->isNotEmpty())
                <p class="mb-3.5 text-[11px] tracking-[.12em] text-(--mut) uppercase">Relations</p>
                <div class="mb-[30px] flex flex-col gap-2.5 text-[13px]">
                    @foreach ($this->relations as $relation)
                        <div class="press rounded-[12px] bg-(--surf) px-3.5 py-2.5" wire:key="relation-{{ $relation->id }}">
                            <p class="mb-0.5 text-[12px] text-(--mut)">{{ $relation->relation_type->label() }}</p>
                            <a href="{{ route('entities.show', $relation->relatedEntity) }}" wire:navigate class="text-(--tx) hover:underline">{{ $relation->relatedEntity->name }}</a>
                        </div>
                    @endforeach
                    @foreach ($this->relatedFrom as $relation)
                        <div class="press rounded-[12px] bg-(--surf) px-3.5 py-2.5" wire:key="related-from-{{ $relation->id }}">
                            <p class="mb-0.5 text-[12px] text-(--mut)">{{ $relation->relation_type->label() }} (entrant)</p>
                            <a href="{{ route('entities.show', $relation->entity) }}" wire:navigate class="text-(--tx) hover:underline">{{ $relation->entity->name }}</a>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($this->brainConfigured)
                <x-brain-notes :documents="$this->brainDocuments" />
            @endif
        </div>
    </div>

    <flux:modal wire:model.self="showEditModal" class="md:w-[420px]">
        <div class="space-y-5" wire:loading.class="opacity-50" wire:target="saveEdit">
            <flux:heading size="lg">Modifier l'entité</flux:heading>

            <div>
                <label class="text-[12px] text-(--mut)">Nom</label>
                <input type="text" wire:model="editName" wire:loading.attr="disabled" wire:target="saveEdit" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none" />
                @error('editName') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[12px] text-(--mut)">Notes</label>
                <textarea wire:model="editNotes" wire:loading.attr="disabled" wire:target="saveEdit" rows="3" class="mt-1.5 w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] text-(--tx) focus:outline-none"></textarea>
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
