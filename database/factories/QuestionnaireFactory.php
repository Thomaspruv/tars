<?php

namespace Database\Factories;

use App\Models\Questionnaire;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Questionnaire>
 */
class QuestionnaireFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'frequency' => 'monthly',
            'anchor' => '1',
            'questions' => [
                ['text' => 'Satisfaction, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
                ['text' => 'Un mot sur la période', 'type' => 'text'],
            ],
            'is_active' => true,
        ];
    }
}
