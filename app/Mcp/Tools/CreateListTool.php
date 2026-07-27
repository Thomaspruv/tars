<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\Checklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create_list')]
#[Description("Crée une nouvelle liste (courses, à emporter...). L'entité de rattachement est optionnelle et résolue par nom approximatif.")]
class CreateListTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Le nom de la liste.')->required(),
            'entity' => $schema->string()->description("Nom approximatif de l'entité de rattachement (optionnel)."),
            'pinned' => $schema->boolean()->description("Épingler la liste sur l'écran Aujourd'hui (optionnel)."),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'entity' => ['nullable', 'string'],
            'pinned' => ['nullable', 'boolean'],
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

        $checklist = Checklist::create([
            'name' => $validated['name'],
            'entity_id' => $entity?->id,
            'is_pinned' => $validated['pinned'] ?? false,
        ]);

        return Response::text("Liste créée : {$checklist->name}.");
    }
}
