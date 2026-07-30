<?php

namespace Database\Factories;

use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'notes' => null,
            'goal_id' => null,
            'milestone_id' => null,
            'entity_id' => null,
            'due_date' => null,
            'scheduled_date' => null,
            'status' => 'todo',
            'priority' => null,
            'is_delegable' => false,
            'recurrence' => null,
            'completed_at' => null,
        ];
    }

    public function scheduledToday(): static
    {
        return $this->state(fn (array $attributes): array => [
            'scheduled_date' => today(),
        ]);
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'done',
            'completed_at' => now(),
        ]);
    }

    public function subtaskOf(Task $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_task_id' => $parent->id,
        ]);
    }
}
