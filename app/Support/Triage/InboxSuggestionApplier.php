<?php

namespace App\Support\Triage;

use App\Enums\InboxConvertedType;
use App\Enums\InboxSuggestionStatus;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxSuggestion;
use App\Models\Note;
use App\Models\Task;
use App\Support\FuzzyMatcher;
use Illuminate\Database\Eloquent\Model;

class InboxSuggestionApplier
{
    public function __construct(private readonly FuzzyMatcher $fuzzyMatcher) {}

    public function apply(InboxSuggestion $suggestion): void
    {
        $fields = $suggestion->suggested_fields;
        $goalId = $this->resolveGoalId($fields['goal'] ?? null);
        $entityId = $this->resolveEntityId($fields['entity'] ?? null);

        $record = $this->createRecord($suggestion->suggested_type, $fields, $goalId, $entityId);

        $suggestion->update([
            'created_type' => $record::class,
            'created_id' => $record->getKey(),
            'status' => InboxSuggestionStatus::Accepted,
        ]);

        $suggestion->inboxItem->update([
            'processed_at' => now(),
            'converted_to_type' => $suggestion->suggested_type,
            'converted_to_id' => $record->getKey(),
        ]);
    }

    public function revert(InboxSuggestion $suggestion): void
    {
        $suggestion->createdRecord?->delete();

        $suggestion->inboxItem->update([
            'processed_at' => null,
            'converted_to_type' => null,
            'converted_to_id' => null,
        ]);

        $suggestion->update(['status' => InboxSuggestionStatus::Rejected]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function createRecord(InboxConvertedType $type, array $fields, ?int $goalId, ?int $entityId): Model
    {
        return match ($type) {
            InboxConvertedType::Task => Task::create([
                'title' => (string) ($fields['title'] ?? ''),
                'notes' => isset($fields['notes']) ? (string) $fields['notes'] : null,
                'due_date' => $fields['due_date'] ?? null,
                'scheduled_date' => $fields['scheduled_date'] ?? null,
                'goal_id' => $goalId,
                'entity_id' => $entityId,
                'status' => 'todo',
            ]),
            InboxConvertedType::Event => Event::create([
                'title' => (string) ($fields['title'] ?? ''),
                'notes' => isset($fields['notes']) ? (string) $fields['notes'] : null,
                'starts_at' => $fields['starts_at'] ?? now()->addDay(),
                'goal_id' => $goalId,
                'entity_id' => $entityId,
            ]),
            InboxConvertedType::Goal => Goal::create([
                'title' => (string) ($fields['title'] ?? ''),
                'description' => isset($fields['notes']) ? (string) $fields['notes'] : null,
                'entity_id' => $entityId,
            ]),
            InboxConvertedType::Note => Note::create([
                'title' => isset($fields['title']) ? (string) $fields['title'] : null,
                'content' => (string) ($fields['content'] ?? $fields['notes'] ?? ''),
                'goal_id' => $goalId,
                'entity_id' => $entityId,
            ]),
        };
    }

    private function resolveGoalId(?string $name): ?int
    {
        if (! $name) {
            return null;
        }

        return $this->fuzzyMatcher->bestMatch(Goal::all(), $name, fn (Goal $goal): string => $goal->title)?->id;
    }

    private function resolveEntityId(?string $name): ?int
    {
        if (! $name) {
            return null;
        }

        return $this->fuzzyMatcher->bestMatch(Entity::all(), $name, fn (Entity $entity): string => $entity->name)?->id;
    }
}
