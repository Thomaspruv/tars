<?php

use App\Enums\DecisionSource;
use App\Enums\OpenDecisionStatus;
use App\Models\Decision;
use App\Models\DecisionCriterion;
use App\Models\DecisionOption;
use App\Models\DecisionScore;
use App\Models\OpenDecision;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Décision')] class extends Component
{
    public OpenDecision $decision;

    public string $contextDraft = '';

    /**
     * Les notes du tableau, indexées `[option_id][critere_id]`. Une case vide
     * est la chaîne vide, pas zéro : « pas encore noté » et « nul sur ce
     * critère » ne veulent pas dire la même chose.
     *
     * @var array<int, array<int, string>>
     */
    public array $grid = [];

    /** @var array<int, string> */
    public array $optionLabels = [];

    /** @var array<int, string> */
    public array $optionNotes = [];

    /** @var array<int, string> */
    public array $criterionLabels = [];

    /** @var array<int, string> */
    public array $weights = [];

    public string $newOptionLabel = '';

    public string $newCriterionLabel = '';

    public string $newCriterionWeight = '3';

    /**
     * La dernière colonne (ou ligne) retirée, gardée en mémoire avec ses notes
     * pour pouvoir la remettre : un × qui efface le travail d'une colonne sans
     * retour possible est un cul-de-sac, pas un raccourci.
     *
     * @var array{kind: string, label: string, notes: string|null, weight: int|null, position: int, scores: array<int, int>}|null
     */
    public ?array $lastRemoval = null;

    public function mount(): void
    {
        $this->reloadDecision();
    }

    #[Computed]
    public function isEditable(): bool
    {
        return $this->decision->status === OpenDecisionStatus::Open;
    }

    /**
     * @return array<int, int|null>
     */
    #[Computed]
    public function scores(): array
    {
        return $this->decision->scores();
    }

    /**
     * Le plafond théorique : toutes les cases à 5. Un score seul ne dit rien,
     * « 47 sur 60 » se lit.
     */
    #[Computed]
    public function maxScore(): int
    {
        return (int) $this->decision->criteria->sum('weight') * 5;
    }

    #[Computed]
    public function leadingOptionId(): ?int
    {
        return $this->decision->leadingOption()?->id;
    }

    public function updatedContextDraft(string $value): void
    {
        if (! $this->isEditable) {
            return;
        }

        $this->decision->update(['context' => $value === '' ? null : $value]);
    }

    public function updatedGrid(string $value, string $key): void
    {
        [$optionId, $criterionId] = array_map('intval', explode('.', $key));

        if (! $this->isEditable || ! $this->ownsOption($optionId) || ! $this->ownsCriterion($criterionId)) {
            return;
        }

        DecisionScore::updateOrCreate(
            ['decision_option_id' => $optionId, 'decision_criterion_id' => $criterionId],
            ['score' => $value === '' ? null : max(0, min(5, (int) $value))],
        );

        $this->reloadDecision();
    }

    public function updatedWeights(string $value, string $key): void
    {
        $criterionId = (int) $key;

        if (! $this->isEditable || ! $this->ownsCriterion($criterionId)) {
            return;
        }

        DecisionCriterion::whereKey($criterionId)->update(['weight' => max(1, min(5, (int) $value))]);

        $this->reloadDecision();
    }

    public function updatedOptionLabels(string $value, string $key): void
    {
        $optionId = (int) $key;

        if (! $this->isEditable || ! $this->ownsOption($optionId) || trim($value) === '') {
            $this->reloadDecision();

            return;
        }

        DecisionOption::whereKey($optionId)->update(['label' => trim($value)]);

        $this->reloadDecision();
    }

    public function updatedCriterionLabels(string $value, string $key): void
    {
        $criterionId = (int) $key;

        if (! $this->isEditable || ! $this->ownsCriterion($criterionId) || trim($value) === '') {
            $this->reloadDecision();

            return;
        }

        DecisionCriterion::whereKey($criterionId)->update(['label' => trim($value)]);

        $this->reloadDecision();
    }

    public function updatedOptionNotes(string $value, string $key): void
    {
        $optionId = (int) $key;

        if (! $this->isEditable || ! $this->ownsOption($optionId)) {
            return;
        }

        DecisionOption::whereKey($optionId)->update(['notes' => $value === '' ? null : $value]);

        $this->reloadDecision();
    }

    public function addOption(): void
    {
        $label = trim($this->newOptionLabel);

        if (! $this->isEditable || $label === '') {
            return;
        }

        $this->decision->options()->create([
            'label' => $label,
            'position' => (int) $this->decision->options->max('position') + 1,
        ]);

        $this->newOptionLabel = '';
        $this->reloadDecision();
    }

    public function addCriterion(): void
    {
        $label = trim($this->newCriterionLabel);

        if (! $this->isEditable || $label === '') {
            return;
        }

        $this->decision->criteria()->create([
            'label' => $label,
            'weight' => max(1, min(5, (int) $this->newCriterionWeight)),
            'position' => (int) $this->decision->criteria->max('position') + 1,
        ]);

        $this->newCriterionLabel = '';
        $this->newCriterionWeight = '3';
        $this->reloadDecision();
    }

    /**
     * Les quatre critères qui reviennent dans presque toutes les décisions.
     * C'est un point de départ à corriger, pas une grille imposée.
     */
    public function seedClassicCriteria(): void
    {
        if (! $this->isEditable || $this->decision->criteria->isNotEmpty()) {
            return;
        }

        foreach ([['Coût', 4], ['Temps', 3], ['Risque', 4], ['Envie', 5]] as $position => [$label, $weight]) {
            $this->decision->criteria()->create([
                'label' => $label,
                'weight' => $weight,
                'position' => $position,
            ]);
        }

        $this->reloadDecision();
    }

    public function removeOption(int $optionId): void
    {
        if (! $this->isEditable || ! $this->ownsOption($optionId)) {
            return;
        }

        $option = $this->decision->options->firstWhere('id', $optionId);

        $this->lastRemoval = [
            'kind' => 'option',
            'label' => $option->label,
            'notes' => $option->notes,
            'weight' => null,
            'position' => $option->position,
            'scores' => $option->scores
                ->filter(fn (DecisionScore $score): bool => $score->score !== null)
                ->mapWithKeys(fn (DecisionScore $score): array => [$score->decision_criterion_id => (int) $score->score])
                ->all(),
        ];

        DecisionOption::whereKey($optionId)->delete();
        $this->reloadDecision();
    }

    public function removeCriterion(int $criterionId): void
    {
        if (! $this->isEditable || ! $this->ownsCriterion($criterionId)) {
            return;
        }

        $criterion = $this->decision->criteria->firstWhere('id', $criterionId);

        $this->lastRemoval = [
            'kind' => 'criterion',
            'label' => $criterion->label,
            'notes' => null,
            'weight' => $criterion->weight,
            'position' => $criterion->position,
            'scores' => $criterion->scores()->whereNotNull('score')->get()
                ->mapWithKeys(fn (DecisionScore $score): array => [$score->decision_option_id => (int) $score->score])
                ->all(),
        ];

        DecisionCriterion::whereKey($criterionId)->delete();
        $this->reloadDecision();
    }

    /**
     * Remet la dernière colonne (ou ligne) retirée à sa place, avec les notes
     * qu'elle portait. Une note dont l'autre bout a disparu entre-temps est
     * abandonnée en silence : il n'y a plus de case où l'écrire.
     */
    public function restoreLastRemoval(): void
    {
        if (! $this->isEditable || $this->lastRemoval === null) {
            return;
        }

        $removal = $this->lastRemoval;
        $this->lastRemoval = null;

        if ($removal['kind'] === 'option') {
            $option = $this->decision->options()->create([
                'label' => $removal['label'],
                'notes' => $removal['notes'],
                'position' => $removal['position'],
            ]);

            foreach ($removal['scores'] as $criterionId => $score) {
                if ($this->ownsCriterion((int) $criterionId)) {
                    DecisionScore::create([
                        'decision_option_id' => $option->id,
                        'decision_criterion_id' => (int) $criterionId,
                        'score' => (int) $score,
                    ]);
                }
            }
        } else {
            $criterion = $this->decision->criteria()->create([
                'label' => $removal['label'],
                'weight' => $removal['weight'] ?? 3,
                'position' => $removal['position'],
            ]);

            foreach ($removal['scores'] as $optionId => $score) {
                if ($this->ownsOption((int) $optionId)) {
                    DecisionScore::create([
                        'decision_option_id' => (int) $optionId,
                        'decision_criterion_id' => $criterion->id,
                        'score' => (int) $score,
                    ]);
                }
            }
        }

        $this->reloadDecision();
    }

    public function dismissLastRemoval(): void
    {
        $this->lastRemoval = null;
    }

    /**
     * Trancher : l'option retenue part au journal des décisions avec l'état du
     * comparateur en contexte, et la question se ferme.
     */
    public function chooseOption(int $optionId): void
    {
        if (! $this->isEditable || ! $this->ownsOption($optionId)) {
            return;
        }

        $option = $this->decision->options->firstWhere('id', $optionId);

        $journalEntry = Decision::create([
            'content' => $this->decision->question.' → '.$option->label,
            'context' => $this->journalContext($option),
            'source' => DecisionSource::Arbitration,
            'goal_id' => $this->decision->goal_id,
            'entity_id' => $this->decision->entity_id,
            'decided_at' => now(),
        ]);

        $this->decision->forceFill([
            'status' => OpenDecisionStatus::Decided,
            'chosen_option_id' => $option->id,
            'decision_id' => $journalEntry->id,
            'closed_at' => now(),
        ])->save();

        $this->reloadDecision();
    }

    public function dropDecision(): void
    {
        if (! $this->isEditable) {
            return;
        }

        $this->decision->forceFill([
            'status' => OpenDecisionStatus::Dropped,
            'closed_at' => now(),
        ])->save();

        $this->reloadDecision();
    }

    /**
     * On ne rouvre qu'une décision abandonnée : une décision tranchée a déjà
     * sa ligne au journal, et le journal ne se réécrit pas.
     */
    public function reopenDecision(): void
    {
        if ($this->decision->status !== OpenDecisionStatus::Dropped) {
            return;
        }

        $this->decision->forceFill([
            'status' => OpenDecisionStatus::Open,
            'closed_at' => null,
        ])->save();

        $this->reloadDecision();
    }

    private function journalContext(DecisionOption $chosen): string
    {
        $scores = $this->decision->scores();

        $line = $this->decision->options
            ->map(fn (DecisionOption $option): string => $option->label.' : '.($scores[$option->id] ?? '—').' pts')
            ->implode(' · ');

        $parts = array_filter([
            $this->decision->context,
            $line === '' ? null : 'Comparateur — '.$line,
            'Retenu : '.$chosen->label,
        ]);

        return implode("\n\n", $parts);
    }

    private function ownsOption(int $optionId): bool
    {
        return $this->decision->options->contains('id', $optionId);
    }

    private function ownsCriterion(int $criterionId): bool
    {
        return $this->decision->criteria->contains('id', $criterionId);
    }

    /**
     * Recharge le modèle et remet les champs du formulaire d'accord avec lui —
     * le tableau est édité case par case, il ne peut pas dériver de la base.
     */
    private function reloadDecision(): void
    {
        $this->decision->refresh()->load(['options.scores', 'criteria', 'goal', 'entity', 'chosenOption']);

        $this->grid = [];
        $this->optionLabels = [];
        $this->optionNotes = [];
        $this->criterionLabels = [];
        $this->weights = [];

        foreach ($this->decision->criteria as $criterion) {
            $this->criterionLabels[$criterion->id] = $criterion->label;
            $this->weights[$criterion->id] = (string) $criterion->weight;
        }

        foreach ($this->decision->options as $option) {
            $this->optionLabels[$option->id] = $option->label;
            $this->optionNotes[$option->id] = (string) $option->notes;

            foreach ($this->decision->criteria as $criterion) {
                $score = $option->scores->firstWhere('decision_criterion_id', $criterion->id)?->score;
                $this->grid[$option->id][$criterion->id] = $score === null ? '' : (string) $score;
            }
        }

        $this->contextDraft = (string) $this->decision->context;

        unset($this->scores, $this->maxScore, $this->leadingOptionId, $this->isEditable);
    }
};
?>

@php
    $cellSelect = 'font-display w-full rounded-[14px] bg-(--surf2) px-3 py-2.5 text-center text-[17px] font-bold text-(--tx) focus:outline-none disabled:opacity-60';
    $textField = 'w-full rounded-[12px] bg-transparent px-2.5 py-2 text-[16px] font-semibold text-(--tx) focus:bg-(--surf2) focus:outline-none';
@endphp

<div>
    <div class="mb-4">
        <a href="{{ route('decisions.index') }}" wire:navigate class="text-[14px] font-medium text-(--mut) hover:text-(--tx)">← Décisions</a>
    </div>

    <x-screen-header
        :kicker="collect([$this->decision->goal?->title, $this->decision->entity?->name, $this->decision->due_date?->translatedFormat('\a\v\a\n\t \l\e j F')])->filter()->implode(' · ') ?: 'décision à trancher'"
        :title="$this->decision->question"
        class="mb-[22px]"
    >
        <x-slot:actions>
            @if ($this->isEditable)
                <x-btn variant="ghost" wire:click="dropDecision">Abandonner</x-btn>
            @elseif ($this->decision->status === App\Enums\OpenDecisionStatus::Dropped)
                <x-btn variant="secondary" wire:click="reopenDecision">Rouvrir</x-btn>
            @endif
        </x-slot:actions>
    </x-screen-header>

    @if ($this->decision->status === App\Enums\OpenDecisionStatus::Decided)
        <x-card tone="agent" padding="18" class="mb-4">
            <p class="text-[16px] leading-[1.5] font-medium text-(--ai)">
                ✓ Tranchée le {{ $this->decision->closed_at?->translatedFormat('j F Y') }} :
                <span class="font-semibold">{{ $this->decision->chosenOption?->label }}</span>
                — inscrite au journal des décisions.
            </p>
        </x-card>
    @elseif ($this->decision->status === App\Enums\OpenDecisionStatus::Dropped)
        <x-card tone="sunk" padding="18" class="mb-4">
            <p class="text-[16px] leading-[1.5] font-medium text-(--mut)">Abandonnée{{ $this->decision->closed_at ? ' le '.$this->decision->closed_at->translatedFormat('j F Y') : '' }}. Le tableau est figé.</p>
        </x-card>
    @endif

    <x-card padding="22" class="mb-4">
        <label class="text-[13px] font-semibold text-(--mut) kicker">Contexte</label>
        <textarea
            wire:model.blur="contextDraft"
            @disabled(! $this->isEditable)
            rows="3"
            placeholder="Ce qu'il faut savoir pour juger : le budget, l'échéance réelle, ce qui a déjà été essayé."
            class="mt-2.5 w-full resize-y rounded-[12px] bg-transparent text-[16px] leading-[1.6] font-medium text-(--tx) placeholder:text-(--mut) focus:outline-none"
        ></textarea>
    </x-card>

    {{-- Retirer une colonne emporte aussi ses notes : tant qu'on est sur la
         page, le tableau garde de quoi la remettre exactement où elle était.
         Un × sans retour possible est un cul-de-sac, pas un raccourci. --}}
    @if ($this->isEditable && $this->lastRemoval)
        @php $keptNotes = count($this->lastRemoval['scores']); @endphp

        <x-card tone="sunk" padding="16" class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-2.5">
            <p class="text-[15px] font-medium text-(--mut)">
                {{ $this->lastRemoval['kind'] === 'option' ? 'Option retirée' : 'Critère retiré' }} :
                <span class="font-bold text-(--tx)">{{ $this->lastRemoval['label'] }}</span>
                @if ($keptNotes > 0)
                    · {{ $keptNotes }} {{ Str::plural('note', $keptNotes) }} {{ Str::plural('gardée', $keptNotes) }} de côté
                @endif
            </p>

            <div class="ml-auto flex shrink-0 items-center gap-2">
                <x-btn variant="primary" size="sm" wire:click="restoreLastRemoval">Rétablir</x-btn>
                <x-btn variant="ghost" size="sm" wire:click="dismissLastRemoval">Ignorer</x-btn>
            </div>
        </x-card>
    @endif

    @if ($this->decision->criteria->isEmpty() && $this->decision->options->isEmpty())
        <x-card tone="dashed" padding="22" class="max-w-[620px]">
            <p class="mb-4 text-[16px] leading-[1.6] font-medium text-(--mut)">
                Le tableau est vide. Posez les options en colonnes, les critères en lignes, mettez un poids
                à chaque critère — Tars fait la somme pondérée et vous dit qui gagne.
            </p>
            <x-btn variant="secondary" wire:click="seedClassicCriteria">Partir des critères classiques</x-btn>
        </x-card>
    @endif

    @if ($this->decision->criteria->isNotEmpty() || $this->decision->options->isNotEmpty())
        <x-card padding="0" class="overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full border-separate border-spacing-0 text-[15px]">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 w-[280px] min-w-[280px] bg-(--surf) px-[22px] py-4 text-left text-[13px] font-semibold text-(--mut) kicker">
                                Critère
                            </th>
                            @foreach ($this->decision->options as $option)
                                <th class="min-w-[210px] border-l border-(--bd) px-4 py-4 text-left align-top" wire:key="head-{{ $option->id }}">
                                    <div class="flex items-start gap-1">
                                        <input
                                            type="text"
                                            wire:model.blur="optionLabels.{{ $option->id }}"
                                            @disabled(! $this->isEditable)
                                            class="{{ $textField }} font-display text-[18px] leading-[1.25] font-bold"
                                        />
                                        @if ($this->isEditable)
                                            <button type="button" wire:click="removeOption({{ $option->id }})" aria-label="Retirer cette option"
                                                title="Retirer la colonne — rétablissable juste après" class="press mt-1.5 shrink-0 px-1 text-[17px] leading-none text-(--mut) hover:text-(--dgr)">×</button>
                                        @endif
                                    </div>
                                </th>
                            @endforeach
                            @if ($this->isEditable)
                                <th class="min-w-[210px] border-l border-(--bd) px-4 py-4 align-top">
                                    <form wire:submit="addOption" class="flex flex-col gap-2">
                                        <input
                                            type="text"
                                            wire:model="newOptionLabel"
                                            placeholder="Nouvelle option"
                                            class="w-full rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] font-medium text-(--tx) placeholder:text-(--mut) focus:outline-none"
                                        />
                                        <x-btn type="submit" variant="secondary" size="sm">Ajouter</x-btn>
                                    </form>
                                </th>
                            @endif
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($this->decision->criteria as $criterion)
                            <tr wire:key="row-{{ $criterion->id }}">
                                <td class="sticky left-0 z-10 border-t border-(--bd) bg-(--surf) px-[22px] py-3.5">
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            wire:model.blur="criterionLabels.{{ $criterion->id }}"
                                            @disabled(! $this->isEditable)
                                            class="{{ $textField }}"
                                        />
                                        <select
                                            wire:model.live="weights.{{ $criterion->id }}"
                                            @disabled(! $this->isEditable)
                                            aria-label="Poids du critère {{ $criterion->label }}"
                                            class="shrink-0 rounded-full bg-(--surf2) px-3 py-2 text-[14px] font-bold text-(--tx) focus:outline-none disabled:opacity-60"
                                        >
                                            @foreach (range(1, 5) as $weight)
                                                <option value="{{ $weight }}">×{{ $weight }}</option>
                                            @endforeach
                                        </select>
                                        @if ($this->isEditable)
                                            <button type="button" wire:click="removeCriterion({{ $criterion->id }})" aria-label="Retirer le critère {{ $criterion->label }}"
                                            title="Retirer la ligne — rétablissable juste après" class="press shrink-0 px-1 text-[17px] leading-none text-(--mut) hover:text-(--dgr)">×</button>
                                        @endif
                                    </div>
                                </td>

                                @foreach ($this->decision->options as $option)
                                    <td class="border-t border-l border-(--bd) px-4 py-3" wire:key="cell-{{ $option->id }}-{{ $criterion->id }}">
                                        <select
                                            wire:model.live="grid.{{ $option->id }}.{{ $criterion->id }}"
                                            @disabled(! $this->isEditable)
                                            aria-label="Note de {{ $option->label }} sur {{ $criterion->label }}"
                                            class="{{ $cellSelect }}"
                                        >
                                            <option value="">—</option>
                                            @foreach (range(0, 5) as $note)
                                                <option value="{{ $note }}">{{ $note }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endforeach

                                @if ($this->isEditable)
                                    <td class="border-t border-l border-(--bd)"></td>
                                @endif
                            </tr>
                        @endforeach

                        @if ($this->isEditable)
                            <tr>
                                <td class="sticky left-0 z-10 border-t border-(--bd) bg-(--surf) px-[22px] py-3.5">
                                    <form wire:submit="addCriterion" class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            wire:model="newCriterionLabel"
                                            placeholder="Nouveau critère"
                                            class="min-w-0 flex-1 rounded-full bg-(--surf2) px-4 py-2.5 text-[14px] font-medium text-(--tx) placeholder:text-(--mut) focus:outline-none"
                                        />
                                        <select wire:model="newCriterionWeight" aria-label="Poids du nouveau critère" class="shrink-0 rounded-full bg-(--surf2) px-3 py-2 text-[14px] font-bold text-(--tx) focus:outline-none">
                                            @foreach (range(1, 5) as $weight)
                                                <option value="{{ $weight }}">×{{ $weight }}</option>
                                            @endforeach
                                        </select>
                                        <x-btn type="submit" variant="secondary" size="sm">Ajouter</x-btn>
                                    </form>
                                </td>
                                <td class="border-t border-(--bd)" colspan="{{ $this->decision->options->count() + 1 }}"></td>
                            </tr>
                        @endif

                        @if ($this->decision->options->isNotEmpty())
                            <tr>
                                <td class="sticky left-0 z-10 border-t border-(--bd) bg-(--surf) px-[22px] py-3.5 align-top text-[13px] font-semibold text-(--mut) kicker">
                                    Notes
                                </td>
                                @foreach ($this->decision->options as $option)
                                    <td class="border-t border-l border-(--bd) px-4 py-3 align-top" wire:key="notes-{{ $option->id }}">
                                        <textarea
                                            wire:model.blur="optionNotes.{{ $option->id }}"
                                            @disabled(! $this->isEditable)
                                            rows="2"
                                            placeholder="ce qu'on sait"
                                            class="w-full resize-y rounded-[12px] bg-transparent px-2.5 py-2 text-[15px] leading-[1.55] font-medium text-(--mut) placeholder:text-(--mut) focus:bg-(--surf2) focus:text-(--tx) focus:outline-none"
                                        ></textarea>
                                    </td>
                                @endforeach
                                @if ($this->isEditable)
                                    <td class="border-t border-l border-(--bd)"></td>
                                @endif
                            </tr>
                        @endif
                    </tbody>

                    @if ($this->decision->options->isNotEmpty())
                        <tfoot>
                            <tr>
                                <td class="sticky left-0 z-10 border-t-2 border-(--bd2) bg-(--surf) px-[22px] py-4 text-[13px] font-bold text-(--tx) kicker">
                                    Score{{ $this->maxScore > 0 ? ' sur '.$this->maxScore : '' }}
                                </td>
                                @foreach ($this->decision->options as $option)
                                    @php
                                        $score = $this->scores[$option->id] ?? null;
                                        $isLeader = $this->leadingOptionId === $option->id;
                                        $ratio = ($score !== null && $this->maxScore > 0) ? round($score / $this->maxScore * 100) : 0;
                                    @endphp
                                    <td class="border-t-2 border-l border-(--bd2) px-4 py-4 align-bottom" wire:key="score-{{ $option->id }}">
                                        <p class="font-display text-[28px] leading-none font-bold {{ $isLeader ? 'text-(--ac)' : 'text-(--tx)' }}">
                                            {{ $score ?? '—' }}
                                            @if ($isLeader)
                                                <span class="text-[18px]" aria-hidden="true">★</span>
                                                <span class="sr-only">en tête</span>
                                            @endif
                                        </p>
                                        <div class="mt-3 h-[8px] w-full overflow-hidden rounded-full bg-(--surf2)">
                                            <div class="h-full rounded-full {{ $isLeader ? 'bg-(--ac)' : 'bg-(--bd3)' }}" style="width: {{ $ratio }}%"></div>
                                        </div>
                                    </td>
                                @endforeach
                                @if ($this->isEditable)
                                    <td class="border-t-2 border-l border-(--bd2)"></td>
                                @endif
                            </tr>

                            @if ($this->isEditable)
                                <tr>
                                    <td class="sticky left-0 z-10 bg-(--surf) px-[22px] py-4"></td>
                                    @foreach ($this->decision->options as $option)
                                        <td class="border-l border-(--bd) px-4 py-4" wire:key="choose-{{ $option->id }}">
                                            <x-btn
                                                :variant="$this->leadingOptionId === $option->id ? 'primary' : 'secondary'"
                                                size="md"
                                                class="w-full"
                                                wire:click="chooseOption({{ $option->id }})"
                                            >Trancher</x-btn>
                                        </td>
                                    @endforeach
                                    <td class="border-l border-(--bd)"></td>
                                </tr>
                            @endif
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-card>

        @if ($this->isEditable && $this->decision->options->isNotEmpty() && $this->decision->criteria->isEmpty())
            <p class="mt-3.5 text-[14px] font-medium text-(--mut)">Ajoutez au moins un critère : sans ligne à noter, il n'y a pas de score à comparer.</p>
        @endif
    @endif
</div>
