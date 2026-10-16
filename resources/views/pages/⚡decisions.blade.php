<?php

use App\Enums\OpenDecisionStatus;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\OpenDecision;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Décisions')] class extends Component
{
    public bool $showCreateModal = false;

    public string $question = '';

    public string $context = '';

    public ?int $goalId = null;

    public ?int $entityId = null;

    public ?string $dueDate = null;

    /**
     * Ce qui attend un arbitrage : les plus urgentes d'abord, les sans-date
     * ensuite. Une décision sans échéance n'est pas moins importante, mais
     * elle ne pousse personne.
     *
     * @return Collection<int, OpenDecision>
     */
    #[Computed]
    public function openDecisions(): Collection
    {
        return OpenDecision::where('status', OpenDecisionStatus::Open)
            ->with(['goal', 'entity', 'options.scores', 'criteria'])
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return Collection<int, OpenDecision>
     */
    #[Computed]
    public function closedDecisions(): Collection
    {
        return OpenDecision::whereIn('status', [OpenDecisionStatus::Decided, OpenDecisionStatus::Dropped])
            ->with(['goal', 'chosenOption'])
            ->orderByDesc('closed_at')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function headerCounts(): string
    {
        $open = $this->openDecisions->count();
        $closed = $this->closedDecisions->count();

        if ($open === 0 && $closed === 0) {
            return 'rien à trancher';
        }

        $parts = [$open.' '.Str::plural('à trancher', max($open, 1))];

        if ($closed > 0) {
            $parts[] = $closed.' '.Str::plural('classée', $closed);
        }

        return implode(' · ', $parts);
    }

    /**
     * @return Collection<int, Goal>
     */
    #[Computed]
    public function allGoals(): Collection
    {
        return Goal::orderBy('title')->get();
    }

    /**
     * @return Collection<int, Entity>
     */
    #[Computed]
    public function allEntities(): Collection
    {
        return Entity::orderBy('name')->get();
    }

    public function openCreateModal(): void
    {
        $this->reset(['question', 'context', 'goalId', 'entityId', 'dueDate']);
        $this->showCreateModal = true;
    }

    public function createDecision(): mixed
    {
        $validated = $this->validate([
            'question' => ['required', 'string', 'max:255'],
            'context' => ['nullable', 'string'],
            'goalId' => ['nullable', 'exists:goals,id'],
            'entityId' => ['nullable', 'exists:entities,id'],
            'dueDate' => ['nullable', 'date'],
        ]);

        $decision = OpenDecision::create([
            'question' => $validated['question'],
            'context' => $validated['context'] ?: null,
            'goal_id' => $validated['goalId'],
            'entity_id' => $validated['entityId'],
            'due_date' => $validated['dueDate'],
        ]);

        $this->showCreateModal = false;

        // On enchaîne directement sur le comparateur : poser la question sans
        // poser les options ne sert à rien.
        return $this->redirect(route('decisions.show', $decision), navigate: true);
    }
};
?>

<div>
    <x-space-tabs class="mb-3.5 -ml-3.5" />

    <x-screen-header :kicker="$this->headerCounts" title="Décisions" class="mb-[22px]">
        <x-slot:actions>
            <x-btn variant="primary" wire:click="openCreateModal">Nouvelle décision</x-btn>
        </x-slot:actions>
    </x-screen-header>

    @forelse ($this->openDecisions as $decision)
        @php
            $leader = $decision->leadingOption();
            $scores = $decision->scores();
            $rated = collect($scores)->filter(fn ($score) => $score !== null);
            // Même échelle que le comparateur : la barre se lit par rapport au
            // score maximum atteignable, pas par rapport à la meilleure option.
            $maxScore = $decision->criteria->sum('weight') * 5;
        @endphp

        <a
            href="{{ route('decisions.show', $decision) }}"
            wire:navigate
            wire:key="decision-{{ $decision->id }}"
            class="press mb-3 block"
        >
            <x-card padding="22" class="transition-colors duration-(--dur-swap) ease-(--ease) hover:bg-(--surf2)">
                <div class="mb-2 flex flex-wrap items-center gap-2.5">
                    @if ($decision->goal)
                        <x-badge type="goal" :goal="$decision->goal" class="font-medium" />
                    @endif
                    @if ($decision->entity)
                        <x-badge type="entity" :name="$decision->entity->name" class="font-medium" />
                    @endif
                    @if ($decision->due_date)
                        <x-badge
                            type="pill"
                            :tone="$decision->due_date->isPast() ? 'danger' : 'warn'"
                            :label="'à trancher avant le '.$decision->due_date->translatedFormat('j F')"
                            class="font-semibold"
                        />
                    @endif
                </div>

                <p class="font-display mb-2.5 text-[22px] leading-[1.25] font-bold text-(--tx)">{{ $decision->question }}</p>

                @if ($decision->context)
                    <p class="mb-3.5 text-[15px] leading-[1.6] font-medium text-(--mut)">{{ Str::limit($decision->context, 180) }}</p>
                @endif

                <p class="text-[15px] leading-[1.5] font-medium text-(--mut)">
                    @if ($decision->options->isEmpty())
                        Aucune option posée — le tableau est vide.
                    @elseif ($leader)
                        <span class="text-(--tx)">En tête : <span class="font-display text-[17px] font-bold">{{ $leader->label }}</span></span>
                        <span class="text-(--mut)"> · {{ $decision->criteria->count() }} {{ Str::plural('critère', $decision->criteria->count()) }} pesés</span>
                    @elseif ($rated->isNotEmpty())
                        Égalité en tête · {{ $decision->options->count() }} {{ Str::plural('option', $decision->options->count()) }}
                    @else
                        {{ $decision->options->count() }} {{ Str::plural('option', $decision->options->count()) }}, pas encore notées
                    @endif
                </p>

                {{-- Le classement en clair dès la liste : une rangée de barres à
                     la même grammaire que les jalons d'un objectif. Une option
                     jamais notée garde sa ligne, vide — c'est ce qui reste à
                     faire, pas un zéro. --}}
                @if ($rated->isNotEmpty())
                    <div class="mt-4 flex flex-col gap-2.5">
                        @foreach ($decision->options as $option)
                            @php
                                $score = $scores[$option->id] ?? null;
                                $isLeader = $leader?->id === $option->id;
                                $ratio = ($score !== null && $maxScore > 0) ? round($score / $maxScore * 100) : 0;
                            @endphp
                            <div class="flex items-center gap-3" wire:key="bar-{{ $decision->id }}-{{ $option->id }}">
                                <span class="w-[150px] shrink-0 truncate text-[15px] {{ $isLeader ? 'font-bold text-(--tx)' : 'font-medium text-(--mut)' }}">{{ $option->label }}</span>
                                <div class="h-[10px] flex-1 overflow-hidden rounded-full bg-(--surf2)" aria-hidden="true">
                                    <div class="h-full rounded-full {{ $isLeader ? 'bg-(--ac)' : 'bg-(--bd3)' }}" style="width: {{ $ratio }}%"></div>
                                </div>
                                <span class="w-[62px] shrink-0 text-right text-[15px] {{ $isLeader ? 'font-bold text-(--ac)' : 'font-medium text-(--mut)' }}">
                                    {{ $score !== null ? $score.' pts' : '—' }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-card>
        </a>
    @empty
        <x-card tone="dashed" padding="22" class="max-w-[580px]">
            <p class="text-[16px] leading-[1.6] font-medium text-(--mut)">
                Rien à trancher. Une décision qui traîne dans la tête coûte plus cher qu'une décision posée
                sur un tableau : écrivez la question, les options suivront.
            </p>
            <x-btn variant="primary" size="md" class="mt-4 self-start" wire:click="openCreateModal">Poser une décision</x-btn>
        </x-card>
    @endforelse

    @if ($this->closedDecisions->isNotEmpty())
        <div class="mt-8 mb-3 flex items-center gap-2.5">
            <h2 class="font-display text-[18px] font-bold text-(--tx)">Classées</h2>
            <span class="text-[14px] font-medium text-(--mut)">{{ $this->closedDecisions->count() }}</span>
        </div>

        <div class="flex flex-col gap-2">
            @foreach ($this->closedDecisions as $decision)
                <a
                    href="{{ route('decisions.show', $decision) }}"
                    wire:navigate
                    wire:key="closed-{{ $decision->id }}"
                    class="press block"
                >
                    <x-card tone="sunk" padding="18">
                        <p class="text-[16px] leading-[1.45] font-semibold text-(--tx)">{{ $decision->question }}</p>
                        <p class="mt-1.5 text-[14px] font-medium text-(--mut)">
                            @if ($decision->status === App\Enums\OpenDecisionStatus::Decided)
                                ✓ {{ $decision->chosenOption?->label ?? 'tranchée' }}
                            @else
                                Abandonnée
                            @endif
                            @if ($decision->closed_at)
                                · {{ $decision->closed_at->translatedFormat('j F Y') }}
                            @endif
                        </p>
                    </x-card>
                </a>
            @endforeach
        </div>
    @endif

    <flux:modal wire:model.self="showCreateModal" class="md:w-[460px]">
        <div class="space-y-5">
            <flux:heading size="lg">Nouvelle décision</flux:heading>

            <div>
                <label class="text-[13px] font-semibold text-(--mut)">La question à trancher</label>
                <x-input type="text" wire:model="question" placeholder="Est-ce que je change de voiture ?" class="mt-1.5 w-full" />
                @error('question') <p class="mt-1 text-[12px] text-(--dgr)">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="text-[13px] font-semibold text-(--mut)">Contexte (optionnel)</label>
                <textarea wire:model="context" rows="3" class="mt-1.5 w-full rounded-[18px] bg-(--surf2) px-4 py-3 text-[15px] leading-[1.6] font-medium text-(--tx) focus:outline-none"></textarea>
            </div>

            <div>
                <label class="text-[13px] font-semibold text-(--mut)">Objectif lié (optionnel)</label>
                <x-select wire:model="goalId" class="mt-1.5 w-full">
                    <option value="">—</option>
                    @foreach ($this->allGoals as $goal)
                        <option value="{{ $goal->id }}">{{ $goal->title }}</option>
                    @endforeach
                </x-select>
            </div>

            <div>
                <label class="text-[13px] font-semibold text-(--mut)">Entité liée (optionnel)</label>
                <x-select wire:model="entityId" class="mt-1.5 w-full">
                    <option value="">—</option>
                    @foreach ($this->allEntities as $entity)
                        <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                    @endforeach
                </x-select>
            </div>

            <div>
                <label class="text-[13px] font-semibold text-(--mut)">À trancher avant (optionnel)</label>
                <x-input type="date" wire:model="dueDate" class="mt-1.5 w-full" />
            </div>

            <div class="flex justify-end gap-2">
                <x-btn variant="ghost" wire:click="$set('showCreateModal', false)">Annuler</x-btn>
                <x-btn variant="primary" wire:click="createDecision">Ouvrir le comparateur</x-btn>
            </div>
        </div>
    </flux:modal>
</div>
