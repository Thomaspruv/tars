<?php

namespace Database\Factories;

use App\Enums\EntityRelationType;
use App\Models\Entity;
use App\Models\EntityRelation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntityRelation>
 */
class EntityRelationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entity_id' => Entity::factory(),
            'related_entity_id' => Entity::factory(),
            'relation_type' => EntityRelationType::Other,
            'note' => null,
        ];
    }
}
