<?php

namespace App\Mcp\Tools;

use App\Enums\DecisionSource;
use App\Mcp\Support\AmbiguousToolCall;
use App\Mcp\Support\NameResolver;
use App\Models\Decision;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('log_decision')]
#[Description('Enregistre une décision prise en conversation, avec son contexte et une ancre optionnelle sur une entité ou un objectif.')]
class LogDecisionTool extends Tool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->description('La décision prise.')->required(),
            'context' => $schema->string()->description('Le contexte ou le pourquoi de la décision.'),
            'entity' => $schema->string()->description("Nom approximatif de l'entité concernée."),
            'goal' => $schema->string()->description("Nom approximatif de l'objectif concerné."),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->logDecision($request);
        } catch (AmbiguousToolCall $e) {
            return Response::text($e->getMessage());
        }
    }

    private function logDecision(Request $request): Response
    {
        $validated = $request->validate([
            'content' => ['required', 'string'],
            'context' => ['nullable', 'string'],
            'entity' => ['nullable', 'string'],
            'goal' => ['nullable', 'string'],
        ]);

        $entity = null;

        if (! empty($validated['entity'])) {
            $entity = $this->resolver->disambiguate(
                $this->resolver->entities($validated['entity']),
                fn ($e): string => $e->name,
                "Plusieurs entités correspondent à « {$validated['entity']} »",
            );

            if ($entity === null) {
                return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
            }
        }

        $goal = null;

        if (! empty($validated['goal'])) {
            $goal = $this->resolver->disambiguate(
                $this->resolver->goals($validated['goal']),
                fn ($g): string => $g->title,
                "Plusieurs objectifs correspondent à « {$validated['goal']} »",
            );

            if ($goal === null) {
                return Response::error("Aucun objectif ne correspond à « {$validated['goal']} ».");
            }
        }

        Decision::create([
            'content' => $validated['content'],
            'context' => $validated['context'] ?? null,
            'source' => DecisionSource::Conversation,
            'entity_id' => $entity?->id,
            'goal_id' => $goal?->id,
            'decided_at' => now(),
        ]);

        return Response::text('Décision enregistrée au journal.');
    }
}
