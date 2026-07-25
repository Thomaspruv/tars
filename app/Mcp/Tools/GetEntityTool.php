<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\AmbiguousToolCall;
use App\Mcp\Support\NameResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_entity')]
#[Description("Vue d'ensemble d'une entité par nom approximatif : tâches ouvertes, prochaine échéance récurrente, listes, dernières notes du cerveau.")]
class GetEntityTool extends Tool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()->description("Nom approximatif de l'entité.")->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->getEntity($request);
        } catch (AmbiguousToolCall $e) {
            return Response::text($e->getMessage());
        }
    }

    private function getEntity(Request $request): Response
    {
        $validated = $request->validate(['entity' => ['required', 'string']]);

        $entity = $this->resolver->disambiguate(
            $this->resolver->entities($validated['entity']),
            fn ($e): string => $e->name,
            "Plusieurs entités correspondent à « {$validated['entity']} »",
        );

        if ($entity === null) {
            return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
        }

        $openTasks = $entity->tasks()->open()->count();
        $nextRecurring = $entity->tasks()->whereNotNull('recurrence')->orderBy('due_date')->first();
        $lists = $entity->checklists()->pluck('name')->implode(', ');
        $notes = $entity->brainDocuments()->latest('mtime')->limit(3)->pluck('title')->implode(', ');

        $parts = ["{$openTasks} tâche(s) ouverte(s)"];

        if ($nextRecurring) {
            $due = $nextRecurring->due_date?->translatedFormat('d M') ?? '?';
            $parts[] = "prochaine échéance récurrente : {$nextRecurring->title} ({$due})";
        }

        $parts[] = $lists !== '' ? "listes : {$lists}" : 'aucune liste';
        $parts[] = $notes !== '' ? "dernières notes : {$notes}" : 'aucune note';

        return Response::text("{$entity->name} — ".implode(', ', $parts).'.');
    }
}
