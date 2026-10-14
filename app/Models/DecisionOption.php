<?php

namespace App\Models;

use Database\Factories\DecisionOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Une colonne du tableau comparateur.
 *
 * @property int $id
 * @property int $open_decision_id
 * @property string $label
 * @property string|null $notes
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, DecisionScore> $scores
 */
#[Fillable(['open_decision_id', 'label', 'notes', 'position'])]
class DecisionOption extends Model
{
    /** @use HasFactory<DecisionOptionFactory> */
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
