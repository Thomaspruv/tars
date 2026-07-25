<?php

namespace App\Support\Brain;

use App\Models\Entity;
use App\Models\Goal;
use App\Support\FuzzyMatcher;

class AnchorResolver
{
    public function __construct(private readonly FuzzyMatcher $fuzzyMatcher = new FuzzyMatcher) {}

    public function resolveEntity(string $needle): ?Entity
    {
        return $this->fuzzyMatcher->bestMatch(Entity::all(), $needle, fn (Entity $entity): string => $entity->name);
    }

    public function resolveGoal(string $needle): ?Goal
    {
        return $this->fuzzyMatcher->bestMatch(Goal::all(), $needle, fn (Goal $goal): string => $goal->title);
    }

    /**
     * Resolve every anchor for a parsed document: frontmatter `entity`/`goal` keys plus [[wikilink]] targets.
     *
     * @return array{entities: list<Entity>, goals: list<Goal>}
     */
    public function resolve(ParsedDocument $document): array
    {
        $entities = [];
        $goals = [];

        foreach ($this->frontmatterNeedles($document->frontmatter, 'entity') as $needle) {
            if ($entity = $this->resolveEntity($needle)) {
                $entities[$entity->id] = $entity;
            }
        }

        foreach ($this->frontmatterNeedles($document->frontmatter, 'goal') as $needle) {
            if ($goal = $this->resolveGoal($needle)) {
                $goals[$goal->id] = $goal;
            }
        }

        foreach ($document->wikilinks as $needle) {
            if ($entity = $this->resolveEntity($needle)) {
                $entities[$entity->id] = $entity;
            } elseif ($goal = $this->resolveGoal($needle)) {
                $goals[$goal->id] = $goal;
            }
        }

        return ['entities' => array_values($entities), 'goals' => array_values($goals)];
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     * @return list<string>
     */
    private function frontmatterNeedles(array $frontmatter, string $key): array
    {
        $value = $frontmatter[$key] ?? null;

        return match (true) {
            is_string($value) => [$value],
            is_array($value) => array_values(array_filter($value, 'is_string')),
            default => [],
        };
    }
}
