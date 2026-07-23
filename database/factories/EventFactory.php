<?php

namespace Database\Factories;

use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = fake()->dateTimeBetween('now', '+2 weeks');

        return [
            'title' => fake()->sentence(3),
            'notes' => null,
            'starts_at' => $startsAt,
            'ends_at' => null,
            'goal_id' => null,
            'entity_id' => null,
        ];
    }
}
