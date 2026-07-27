<?php

namespace App\Mcp\Tools;

use App\Enums\EntityType;
use App\Mcp\Support\NameResolver;
use App\Models\Entity;
use App\Models\LifeArea;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_entity')]
#[Description('Crée une entité (entreprise, bien, véhicule...). Le domaine de vie est résolu par nom approximatif, jamais par identifiant.')]
class CreateEntityTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description("Le nom de l'entité.")->required(),
            'type' => $schema->string()->enum(EntityType::class)->description('company, property, vehicle ou other.')->required(),
            'life_area' => $schema->string()->description('Nom approximatif du domaine de vie.')->required(),
            'notes' => $schema->string()->description('Notes libres optionnelles.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(EntityType::class)],
            'life_area' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $lifeArea = $this->resolver->disambiguate(
            $this->resolver->lifeAreas($validated['life_area']),
            fn ($l): string => $l->name,
            "Plusieurs domaines de vie correspondent à « {$validated['life_area']} »",
        );

        if ($lifeArea === null) {
            $existing = LifeArea::pluck('name')->implode(', ');

            return Response::error("Aucun domaine de vie ne correspond à « {$validated['life_area']} ». Domaines existants : {$existing}.");
        }

        $entity = Entity::create([
            'life_area_id' => $lifeArea->id,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'notes' => $validated['notes'] ?? null,
        ]);

        return Response::text("Entité créée : {$entity->name} ({$entity->type->value}, {$lifeArea->name}).");
    }
}
