<?php

namespace App\Models;

use App\Enums\OpenDecisionStatus;
use Database\Factories\OpenDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Une décision *à prendre* — la question ouverte et son tableau comparateur.
 * Le journal des décisions (`Decision`) reste l'historique de ce qui a été
 * tranché : arbitrer une décision ouverte y écrit une ligne et ferme celle-ci.
 *
 * @property int $id
 * @property string $question
 * @property string|null $context
 * @property OpenDecisionStatus $status
 * @property int|null $goal_id
 * @property int|null $entity_id
 * @property Carbon|null $due_date
 * @property int|null $chosen_option_id
 * @property int|null $decision_id
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DecisionOption> $options
 * @property-read Collection<int, DecisionCriterion> $criteria
 */
#[Fillable(['question', 'context', 'status', 'goal_id', 'entity_id', 'due_date'])]
class OpenDecision extends Model
{
    /** @use HasFactory<OpenDecisionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OpenDecisionStatus::class,
            'due_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DecisionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(DecisionOption::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<DecisionCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(DecisionCriterion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<Goal, $this>
     */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    /**
     * @return BelongsTo<Entity, $this>
     */
    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    /**
     * @return BelongsTo<DecisionOption, $this>
     */
    public function chosenOption(): BelongsTo
    {
        return $this->belongsTo(DecisionOption::class, 'chosen_option_id');
    }

    /**
     * La ligne écrite au journal des décisions le jour où on a tranché.
     *
     * @return BelongsTo<Decision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    /**
     * Score pondéré de chaque option : somme des (note × poids du critère).
     * Une option dont aucune case n'est remplie n'a pas de score du tout —
     * `null`, pas zéro : le comparateur ne classe que ce qui a été noté.
     *
     * @return array<int, int|null> option_id => score
     */
    public function scores(): array
    {
        $this->loadMissing(['options.scores', 'criteria']);

        $weights = $this->criteria->pluck('weight', 'id');

        return $this->options->mapWithKeys(function (DecisionOption $option) use ($weights): array {
            $rated = $option->scores->filter(fn (DecisionScore $score): bool => $score->score !== null);

            if ($rated->isEmpty()) {
                return [$option->id => null];
            }

            $total = $rated->sum(
                fn (DecisionScore $score): int => (int) $score->score * (int) ($weights[$score->decision_criterion_id] ?? 0)
            );

            return [$option->id => (int) $total];
        })->all();
    }

    /**
     * L'option en tête, s'il y en a une seule — une égalité ne désigne
     * personne, et c'est une information en soi.
     */
    public function leadingOption(): ?DecisionOption
    {
        $scores = array_filter($this->scores(), fn (?int $score): bool => $score !== null);

        if ($scores === []) {
            return null;
        }

        $best = max($scores);
        $winners = array_keys($scores, $best, true);

        return count($winners) === 1 ? $this->options->firstWhere('id', $winners[0]) : null;
    }
}
