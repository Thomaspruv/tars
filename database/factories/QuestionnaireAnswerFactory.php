<?php

namespace Database\Factories;

use App\Models\QuestionnaireAnswer;
use App\Models\QuestionnaireRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionnaireAnswer>
 */
class QuestionnaireAnswerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'run_id' => QuestionnaireRun::factory(),
            'question_text' => 'Satisfaction, de 1 à 10',
            'type' => 'scale',
            'answer_text' => null,
            'answer_numeric' => fake()->numberBetween(1, 10),
        ];
    }
}
