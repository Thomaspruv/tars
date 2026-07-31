<?php

namespace Database\Factories;

use App\Enums\InboxConvertedType;
use App\Enums\SuggestionConfidence;
use App\Models\AgentRun;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxSuggestion>
 */
class InboxSuggestionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'inbox_item_id' => InboxItem::factory(),
            'suggested_type' => InboxConvertedType::Task,
            'suggested_fields' => ['title' => fake()->sentence(4)],
            'confidence' => SuggestionConfidence::Medium,
            'reason' => fake()->sentence(),
            'status' => 'pending',
            'agent_run_id' => AgentRun::factory(),
        ];
    }
}
