<?php

namespace Database\Factories;

use App\Models\InboxItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxItem>
 */
class InboxItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content' => fake()->sentence(6),
            'processed_at' => null,
            'converted_to_type' => null,
            'converted_to_id' => null,
        ];
    }
}
