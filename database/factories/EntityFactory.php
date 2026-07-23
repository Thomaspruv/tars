<?php

namespace Database\Factories;

use App\Models\Entity;
use App\Models\LifeArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entity>
 */
class EntityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'life_area_id' => LifeArea::factory(),
            'name' => fake()->company(),
            'type' => fake()->randomElement(['company', 'property', 'vehicle', 'other']),
            'context' => fake()->randomElement(['perso', 'pro']),
            'notes' => null,
            'status' => 'active',
        ];
    }
}
