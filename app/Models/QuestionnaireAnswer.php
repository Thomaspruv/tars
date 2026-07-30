<?php

namespace App\Models;

use App\Enums\QuestionType;
use Database\Factories\QuestionnaireAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $run_id
 * @property string $question_text
 * @property QuestionType $type
 * @property string|null $answer_text
 * @property float|null $answer_numeric
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['run_id', 'question_text', 'type', 'answer_text', 'answer_numeric'])]
class QuestionnaireAnswer extends Model
{
    /** @use HasFactory<QuestionnaireAnswerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => QuestionType::class,
            'answer_numeric' => 'float',
        ];
    }

    /**
     * @return BelongsTo<QuestionnaireRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireRun::class, 'run_id');
    }
}
