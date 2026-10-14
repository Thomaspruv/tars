<?php

namespace App\Models;

use Database\Factories\DecisionScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Une case du tableau : la note d'une option sur un critère (0 à 5, ou rien).
 *
 * @property int $id
 * @property int $decision_option_id
 * @property int $decision_criterion_id
 * @property int|null $score
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['decision_option_id', 'decision_criterion_id', 'score'])]
class DecisionScore extends Model
{
    /** @use HasFactory<DecisionScoreFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<DecisionOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(DecisionOption::class, 'decision_option_id');
    }

    /**
     * @return BelongsTo<DecisionCriterion, $this>
     */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(DecisionCriterion::class, 'decision_criterion_id');
    }
}
