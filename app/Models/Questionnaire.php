<?php

namespace App\Models;

use App\Enums\QuestionnaireFrequency;
use Database\Factories\QuestionnaireFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property QuestionnaireFrequency $frequency
 * @property string $anchor
 * @property list<array{text: string, type: string, scale_max?: int}> $questions
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'frequency', 'anchor', 'questions', 'is_active'])]
class Questionnaire extends Model
{
    /** @use HasFactory<QuestionnaireFactory> */
    use HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'frequency' => QuestionnaireFrequency::class,
            'questions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<QuestionnaireRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(QuestionnaireRun::class);
    }
}
