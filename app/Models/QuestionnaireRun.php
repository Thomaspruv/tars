<?php

namespace App\Models;

use App\Enums\QuestionnaireRunStatus;
use Database\Factories\QuestionnaireRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $questionnaire_id
 * @property Carbon $due_date
 * @property QuestionnaireRunStatus $status
 * @property Carbon|null $completed_at
 * @property string|null $note_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['questionnaire_id', 'due_date', 'status', 'completed_at', 'note_path'])]
class QuestionnaireRun extends Model
{
    /** @use HasFactory<QuestionnaireRunFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'status' => QuestionnaireRunStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Questionnaire, $this>
     */
    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(Questionnaire::class);
    }

    /**
     * @return HasMany<QuestionnaireAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(QuestionnaireAnswer::class, 'run_id');
    }
}
