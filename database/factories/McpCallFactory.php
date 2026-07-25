<?php

namespace Database\Factories;

use App\Models\McpCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpCall>
 */
class McpCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tool' => fake()->randomElement(['capture', 'add_task', 'list_tasks', 'get_today']),
            'parameters' => [],
            'result_summary' => fake()->sentence(),
            'status' => 'success',
            'error_message' => null,
            'duration_ms' => fake()->numberBetween(20, 400),
        ];
    }
}
