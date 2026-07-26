<?php

namespace App\Models;

use App\Enums\ReviewType;
use Database\Factories\ReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ReviewType $type
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $generated_content
 * @property list<array{question: string, goal: ?string, entity: ?string, response: ?string, answered_at: ?string}>|null $proposed_decisions
 * @property string|null $user_notes
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['type', 'period_start', 'period_end', 'generated_content', 'proposed_decisions', 'user_notes', 'completed_at'])]
class Review extends Model
{
    /** @use HasFactory<ReviewFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => ReviewType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'proposed_decisions' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Decision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(Decision::class);
    }
}
