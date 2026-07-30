<?php

namespace Database\Factories;

use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuestionnaireRun>
 */
class QuestionnaireRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'questionnaire_id' => Questionnaire::factory(),
            'due_date' => today(),
            'status' => 'pending',
            'completed_at' => null,
            'note_path' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
