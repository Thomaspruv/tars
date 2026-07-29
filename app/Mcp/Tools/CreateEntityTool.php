<?php

namespace App\Mcp\Tools;

use App\Enums\EntityType;
use App\Models\Entity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_entity')]
#[Description('Crée une entité (entreprise, bien, véhicule...).')]
class CreateEntityTool extends LoggedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description("Le nom de l'entité.")->required(),
            'type' => $schema->string()->enum(EntityType::class)->description('company, property, vehicle ou other.')->required(),
            'notes' => $schema->string()->description('Notes libres optionnelles.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(EntityType::class)],
            'notes' => ['nullable', 'string'],
        ]);

        $entity = Entity::create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return Response::text("Entité créée : {$entity->name} ({$entity->type->value}).");
    }
}
