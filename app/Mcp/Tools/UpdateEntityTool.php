<?php

namespace App\Mcp\Tools;

use App\Enums\EntityStatus;
use App\Mcp\Support\NameResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_entity')]
#[Description("Met à jour le nom, les notes ou le statut (active/archived) d'une entité existante, résolue par nom approximatif.")]
class UpdateEntityTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity' => $schema->string()->description("Nom approximatif de l'entité à modifier.")->required(),
            'name' => $schema->string()->description('Nouveau nom (optionnel).'),
            'notes' => $schema->string()->description('Nouvelles notes (optionnel).'),
            'status' => $schema->string()->enum(EntityStatus::class)->description('active ou archived (optionnel).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'entity' => ['required', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::enum(EntityStatus::class)],
        ]);

        $entity = $this->resolver->disambiguate(
            $this->resolver->entities($validated['entity']),
            fn ($e): string => $e->name,
            "Plusieurs entités correspondent à « {$validated['entity']} »",
        );

        if ($entity === null) {
            return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
        }

        $updates = array_filter([
            'name' => $validated['name'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'] ?? null,
        ], fn ($value): bool => $value !== null);

        if ($updates === []) {
            return Response::error('Rien à mettre à jour.');
        }

        $entity->update($updates);

        return Response::text("Entité « {$entity->name} » mise à jour.");
    }
}
