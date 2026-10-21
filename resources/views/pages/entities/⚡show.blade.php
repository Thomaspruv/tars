<?php

use App\Enums\TaskStatus;
use App\Models\Entity;
use App\Models\Task;
use App\Support\Brain\BrainSettings;
use App\Support\Entity\EntityDetailFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Entité')] class extends Component
{
    /**
     * Le doc prévoyait un quatrième filtre, « Mails », nourri par la boîte de
     * Thomas. Le scope `gmail.readonly` est demandé à Google mais aucun code
     * ne lit encore un seul message : le filtre n'existe donc pas ici. Il
     * reviendra le jour où un mail entre vraiment en base.
     */
    public const array HISTORY_FILTERS = [
        'tout' => 'Tout',
        'taches' => 'Tâches',
        'notes' => 'Notes',
        'evenements' => 'Événements',
    ];

    /** Au-delà, l'entité est « muette » et la fiche le dit en haut. */
    private const int SILENT_DAYS = 14;

    public Entity $entity;

    #[Url(as: 'histo')]
    public string $historyFilter = 'tout';

    public bool $showEditModal = false;

    public string $editName = '';

    public string $editNotes = '';

    /** @var array<string, mixed> */
    public array $editDetails = [];

    public string $newNoteContent = '';

    public function mount(): void
    {
        $this->entity->load(['goals.milestones']);

        if (! array_key_exists($this->historyFilter, self::HISTORY_FILTERS)) {
            $this->historyFilter = 'tout';
        }
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function historyFilters(): array
    {
        return self::HISTORY_FILTERS;
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
    public function recurringTasks(): Collection
    {
        return $this->entity->tasks()->whereNotNull('recurrence')->topLevel()->get();
    }

    #[Computed]
    public function notes(): Collection
    {
        return $this->entity->notes()->latest()->get();
    }

    /**
     * L'historique de l'entité : ses tâches, ses notes et ses rendez-vous, dans
     * l'ordre du temps et dans une seule colonne — c'est la mesure de la
     * relation, pas trois listes à recouper.
     *
     * @return list<array{at: CarbonInterface, kind: string, text: string, task: Task|null, done: bool, meta: string|null}>
     */
    #[Computed]
    public function history(): array
    {
        $lines = [];

        if (in_array($this->historyFilter, ['tout', 'taches'], true)) {
            foreach ($this->entity->tasks()->topLevel()->get() as $task) {
                $at = $task->completed_at ?? $task->due_date ?? $task->scheduled_date ?? $task->created_at;

                $lines[] = [
                    'at' => $at,
                    'kind' => 'tâche',
                    'text' => $task->title,
                    'task' => $task,
                    'done' => $task->status === TaskStatus::Done,
                    'meta' => $task->recurrence !== null ? 'récurrente' : null,
                ];
            }
        }

        if (in_array($this->historyFilter, ['tout', 'notes'], true)) {
            foreach ($this->entity->notes()->get() as $note) {
                $lines[] = [
                    'at' => $note->created_at,
                    'kind' => 'note',
                    'text' => $note->title !== null ? $note->title.' : '.$note->content : $note->content,
                    'task' => null,
                    'done' => false,
                    'meta' => $note->source === \App\Enums\NoteSource::Agent ? 'écrite par Hermès' : null,
                ];
            }
        }

        if (in_array($this->historyFilter, ['tout', 'evenements'], true)) {
            foreach ($this->entity->events()->get() as $event) {
                $lines[] = [
                    'at' => $event->starts_at,
                    'kind' => 'événement',
                    'text' => $event->title,
                    'task' => null,
                    'done' => false,
                    'meta' => $event->google_event_id !== null ? 'agenda' : null,
                ];
            }
        }

        usort($lines, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return $lines;
    }

    /**
     * @return list<array{at: CarbonInterface, kind: string, text: string, task: Task|null, done: bool, meta: string|null}>
     */
    #[Computed]
    public function upcoming(): array
    {
        return array_values(array_filter(
            $this->history,
            fn (array $line): bool => ! $line['done'] && $line['at']->isFuture(),
        ));
    }

    /**
     * @return list<array{at: CarbonInterface, kind: string, text: string, task: Task|null, done: bool, meta: string|null}>
     */
    #[Computed]
    public function past(): array
    {
        return array_slice(array_values(array_filter(
            $this->history,
            fn (array $line): bool => $line['done'] || ! $line['at']->isFuture(),
        )), 0, 12);
    }

    /**
     * Le dernier échange réel avec cette entité : une tâche cochée, une note
     * écrite, un rendez-vous tenu. Ce qui est *prévu* ne compte pas — prévoir
     * un appel n'est pas avoir parlé.
     */
    #[Computed]
    public function lastExchangeAt(): ?CarbonInterface
    {
        $dates = collect($this->history)
            ->filter(fn (array $line): bool => $line['done'] || ! $line['at']->isFuture())
            ->pluck('at');

        return $dates->isNotEmpty() ? $dates->sortDesc()->first() : null;
    }

    /**
     * Le nombre de jours de silence, au-delà du seuil — `null` quand la
     * relation est vivante, et la fiche ne dit alors rien du tout.
     */
    #[Computed]
    public function silentDays(): ?int
    {
        if ($this->lastExchangeAt === null) {
            return null;
        }

        $days = (int) $this->lastExchangeAt->startOfDay()->diffInDays(today(), absolute: true);

        return $days >= self::SILENT_DAYS ? $days : null;
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

    public function showHistoryFilter(string $filter): void
    {
        if (! array_key_exists($filter, self::HISTORY_FILTERS)) {
            return;
        }

        $this->historyFilter = $filter;

        $this->forgetHistory();
    }

    public function toggleTask(int $taskId): void
    {
        Task::findOrFail($taskId)->toggleCompletion();

        $this->forgetHistory();
    }

    public function createSubtask(int $parentTaskId, string $title): void
    {
        $title = trim($title);

        if ($title === '') {
            return;
        }

        Task::findOrFail($parentTaskId)->subtasks()->create(['title' => $title, 'status' => 'todo']);

        $this->forgetHistory();
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
        $this->forgetHistory();
    }

    /**
     * « auj. 14:00 » pour aujourd'hui, le jour pour la semaine, la date
     * ensuite : la colonne de gauche de l'historique fait 64px.
     */
    public function stamp(CarbonInterface $at): string
    {
        return match (true) {
            $at->isToday() => 'auj. '.$at->format('H:i'),
            $at->isTomorrow() => 'demain',
            $at->isYesterday() => 'hier',
            $at->between(now(), now()->addDays(6)) => $at->translatedFormat('D j'),
            default => $at->translatedFormat('j M'),
        };
    }

    private function forgetHistory(): void
    {
        unset($this->history, $this->upcoming, $this->past, $this->lastExchangeAt, $this->silentDays, $this->recurringTasks);
    }
};
?>

@php
    $initials = collect(preg_split('/[\s\-]+/', (string) $entity->name))
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
@endphp

{{-- L'entité ne porte qu'une mesure : le temps depuis le dernier échange.
     L'historique mêle tâches, notes et rendez-vous dans l'ordre du temps ;
     les coordonnées restent à droite. --}}
<div>
    <nav class="mb-1.5 flex items-center gap-2 text-[13px] text-(--mut)" aria-label="Fil d'Ariane">
        <a href="{{ route('brain.index') }}" wire:navigate class="hover:text-(--tx)">Mémoire</a>
        <span aria-hidden="true">/</span>
        <a href="{{ route('entities.index') }}" wire:navigate class="hover:text-(--tx)">Entités</a>
        <span aria-hidden="true">/</span>
        <span class="min-w-0 truncate text-(--tx)">{{ $entity->name }}</span>
    </nav>

    <div class="mb-[26px] flex max-w-[1200px] flex-wrap items-center gap-4">
        <span
            class="font-display inline-flex size-[52px] shrink-0 items-center justify-center rounded-full bg-(--acbg) text-[18px] font-bold text-(--ac)"
            aria-hidden="true"
        >{{ $initials }}</span>

        <div class="min-w-0 flex-1">
            <h1 class="font-display text-[34px] leading-[1.1] font-bold tracking-[-.03em] text-(--tx)">{{ $entity->name }}</h1>
            <p class="mt-1 text-[13px] text-(--mut)">
                {{ $entity->type->label() }} · {{ ucfirst($entity->context->value) }} ·
                suivie depuis {{ $entity->created_at->translatedFormat('F Y') }}
            </p>
        </div>

        {{-- Le silence est dit en blé, pas en rouge : c'est une information,
             pas une faute — même règle que l'objectif qui dort. --}}
        @if ($this->silentDays !== null)
            <span class="shrink-0 rounded-full bg-(--warnbg) px-3 py-1.5 text-[13px] font-semibold text-(--warn)">
                Muet depuis {{ $this->silentDays }} jours
            </span>
        @endif

        <div class="flex shrink-0 flex-wrap gap-2">
            <x-btn variant="secondary" wire:click="openEditModal">Modifier</x-btn>
            <x-btn variant="danger" wire:click="archive" wire:confirm="Archiver cette entité ?">Archiver</x-btn>
        </div>
    </div>

    <div class="grid max-w-[1200px] grid-cols-1 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
        <x-card padding="0" class="min-w-0 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-(--bd) px-[18px] py-3">
                <h2 class="font-display text-[17px] font-semibold text-(--tx)">Historique</h2>

                <div class="flex items-center gap-1 rounded-full bg-(--surf2) p-1">
                    @foreach ($this->historyFilters as $key => $label)
                        <button
                            type="button"
                            wire:click="showHistoryFilter('{{ $key }}')"
                            @class([
                                'press rounded-full px-3 py-1 text-[12px]',
                                'bg-(--surf) font-semibold text-(--tx)' => $this->historyFilter === $key,
                                'text-(--mut) hover:text-(--tx)' => $this->historyFilter !== $key,
                            ])
                            @if ($this->historyFilter === $key) aria-current="true" @endif
                        >{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($this->upcoming !== [])
                <p class="border-b border-(--bd) bg-(--sunk) px-[18px] py-2 text-[11px] tracking-[.1em] text-(--mut) uppercase">À venir</p>

                @foreach ($this->upcoming as $line)
                    @include('pages.entities.history-row', ['line' => $line, 'key' => 'up-'.$loop->index])
                @endforeach
            @endif

            @if ($this->past !== [])
                <p class="border-b border-(--bd) bg-(--sunk) px-[18px] py-2 text-[11px] tracking-[.1em] text-(--mut) uppercase">Passé</p>

                @foreach ($this->past as $line)
                    @include('pages.entities.history-row', ['line' => $line, 'key' => 'past-'.$loop->index])
                @endforeach
            @endif

            @if ($this->history === [])
                <p class="px-[18px] py-4 text-[14px] text-(--mut)">Rien dans l'historique pour ce filtre.</p>
            @endif

            {{-- Ajouter une note depuis l'historique : c'est le geste le plus
                 fréquent sur une fiche, il ne doit pas demander un détour. --}}
            <form wire:submit="addNote" class="flex items-center gap-3.5 border-t border-(--bd) px-[18px] py-[13px]">
                <span aria-hidden="true" class="w-[20px] shrink-0 text-center text-(--mut)">+</span>
                <input
                    type="text"
                    wire:model="newNoteContent"
                    placeholder="Ajouter une note à l'historique"
                    class="w-full bg-transparent text-[15px] text-(--tx) placeholder:text-(--mut) focus:outline-none"
                />
            </form>
            @error('newNoteContent') <p class="px-[18px] pb-3 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
        </x-card>

        <div class="flex min-w-0 flex-col gap-4">
            @if ($this->filledDetails !== [])
                <x-card padding="0" class="overflow-hidden">
                    <p class="border-b border-(--bd) px-4 py-2.5 text-[11px] tracking-[.1em] text-(--mut) uppercase">Coordonnées</p>

                    @foreach ($this->filledDetails as $pair)
                        <div class="flex items-baseline justify-between gap-3 border-b border-(--bd) px-4 py-2.5 text-[14px] last:border-b-0" wire:key="detail-{{ $loop->index }}">
                            <span class="shrink-0 text-(--mut)">{{ $pair['label'] }}</span>
                            <span class="min-w-0 text-right font-medium text-(--tx)">{{ $pair['value'] }}</span>
                        </div>
                    @endforeach
                </x-card>
            @endif

            @if ($this->silentDays !== null && $this->lastExchangeAt !== null)
                {{-- Pas de filet vert : aucun agent ne surveille cette entité,
                     c'est l'app qui compte les jours. Le doc prêtait cette
                     carte à Oto — on ne signe pas d'une voix qui n'a pas
                     parlé. --}}
                <x-card padding="16">
                    <p class="mb-2 text-[11px] tracking-[.1em] text-(--mut) uppercase">Silence</p>
                    <p class="text-[14px] leading-[1.55] text-(--tx)">
                        Dernier échange le {{ $this->lastExchangeAt->translatedFormat('j F') }}, il y a
                        {{ $this->silentDays }} jours. Rien n'est prévu de ton côté depuis.
                    </p>
                </x-card>
            @endif

            @if ($entity->goals->isNotEmpty() || $this->notes->isNotEmpty() || $this->relations->isNotEmpty() || $this->relatedFrom->isNotEmpty())
                <x-card padding="0" class="overflow-hidden">
                    <p class="border-b border-(--bd) px-4 py-2.5 text-[11px] tracking-[.1em] text-(--mut) uppercase">Lié à</p>

                    @foreach ($entity->goals as $goal)
                        @php $done = $goal->milestones->where('status', \App\Enums\MilestoneStatus::Done)->count(); @endphp
                        <a
                            href="{{ route('goals.show', $goal) }}"
                            wire:navigate
                            wire:key="linked-goal-{{ $goal->id }}"
                            class="press-row flex items-center gap-2.5 border-b border-(--bd) px-4 py-2.5 last:border-b-0 hover:bg-(--surf2)"
                        >
                            <span class="size-[8px] shrink-0 rounded-[3px]" style="background: var(--goal-{{ $goal->color_slot }})" aria-hidden="true"></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-(--tx)">{{ $goal->title }}</span>
                                <span class="block text-[12px] text-(--mut)">objectif · {{ $done }} / {{ $goal->milestones->count() }}</span>
                            </span>
                        </a>
                    @endforeach

                    @if ($this->notes->isNotEmpty())
                        <div class="border-b border-(--bd) px-4 py-2.5 text-[14px] last:border-b-0">
                            <span class="block text-[14px] font-medium text-(--tx)">{{ $this->notes->count() }} {{ Str::plural('note', $this->notes->count()) }}</span>
                            <span class="block text-[12px] text-(--mut)">dans l'historique</span>
                        </div>
                    @endif

                    @foreach ($this->relations as $relation)
                        <a
                            href="{{ route('entities.show', $relation->relatedEntity) }}"
                            wire:navigate
                            wire:key="relation-{{ $relation->id }}"
                            class="press-row block border-b border-(--bd) px-4 py-2.5 last:border-b-0 hover:bg-(--surf2)"
                        >
                            <span class="block truncate text-[14px] font-medium text-(--tx)">{{ $relation->relatedEntity->name }}</span>
                            <span class="block text-[12px] text-(--mut)">{{ $relation->relation_type->label() }}</span>
                        </a>
                    @endforeach

                    @foreach ($this->relatedFrom as $relation)
                        <a
                            href="{{ route('entities.show', $relation->entity) }}"
                            wire:navigate
                            wire:key="related-from-{{ $relation->id }}"
                            class="press-row block border-b border-(--bd) px-4 py-2.5 last:border-b-0 hover:bg-(--surf2)"
                        >
                            <span class="block truncate text-[14px] font-medium text-(--tx)">{{ $relation->entity->name }}</span>
                            <span class="block text-[12px] text-(--mut)">{{ $relation->relation_type->label() }} (entrant)</span>
                        </a>
                    @endforeach
                </x-card>
            @endif

            @if ($this->recurringTasks->isNotEmpty())
                <x-card padding="0" class="overflow-hidden">
                    <p class="border-b border-(--bd) px-4 py-2.5 text-[11px] tracking-[.1em] text-(--mut) uppercase">Échéances récurrentes</p>

                    @foreach ($this->recurringTasks as $task)
                        <div class="flex items-center gap-2.5 border-b border-(--bd) px-4 py-2.5 last:border-b-0" wire:key="recurring-{{ $task->id }}">
                            <span class="min-w-0 flex-1 truncate text-[14px] text-(--tx)">{{ $task->title }}</span>
                            <x-badge type="recur" :recurrence="$task->recurrence" />
                        </div>
                    @endforeach
                </x-card>
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
                <textarea wire:model="editNotes" wire:loading.attr="disabled" wire:target="saveEdit" rows="3" class="mt-1.5 w-full rounded-[18px] bg-(--surf2) px-4 py-3 text-[14px] text-(--tx) focus:outline-none"></textarea>
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
