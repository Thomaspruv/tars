<?php

namespace Database\Factories;

use App\Models\LifeArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LifeArea>
 */
class LifeAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Pro', 'Immobilier', 'Finances', 'Santé', 'Perso']),
            'color' => fake()->hexColor(),
            'icon' => null,
            'position' => fake()->numberBetween(0, 10),
        ];
    }
}
