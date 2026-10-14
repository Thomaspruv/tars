<?php

namespace App\Models;

use Database\Factories\DecisionCriterionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Une ligne du tableau comparateur, avec son poids (1 à 5).
 *
 * @property int $id
 * @property int $open_decision_id
 * @property string $label
 * @property int $weight
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DecisionScore> $scores
 */
#[Fillable(['open_decision_id', 'label', 'weight', 'position'])]
class DecisionCriterion extends Model
{
    /** @use HasFactory<DecisionCriterionFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<OpenDecision, $this>
     */
    public function openDecision(): BelongsTo
    {
        return $this->belongsTo(OpenDecision::class);
    }

    /**
     * @return HasMany<DecisionScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(DecisionScore::class);
    }
}
