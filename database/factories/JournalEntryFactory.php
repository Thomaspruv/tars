<?php

namespace Database\Factories;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JournalEntry>
 */
class JournalEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $date = fake()->unique()->dateTimeBetween('-3 months', 'now');

        return [
            'date' => $date,
            'mood' => fake()->numberBetween(1, 10),
            'mood_label' => fake()->randomElement(['calme', 'productif', 'fatigué', 'content']),
            'summary' => fake()->realText(200),
            'highlights' => [fake()->sentence(6)],
            'source' => 'manual',
            'note_path' => 'TARS/Journal/'.$date->format('Y').'/'.$date->format('Y-m-d').'.md',
        ];
    }
}
