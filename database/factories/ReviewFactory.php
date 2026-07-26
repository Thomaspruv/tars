<?php

namespace Database\Factories;

use App\Enums\ReviewType;
use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-1 month', 'now');

        return [
            'type' => ReviewType::Weekly,
            'period_start' => $periodStart,
            'period_end' => (clone $periodStart)->modify('+6 days'),
            'generated_content' => "# Revue\n\n".fake()->realText(400),
            'user_notes' => null,
            'completed_at' => null,
        ];
    }
}
