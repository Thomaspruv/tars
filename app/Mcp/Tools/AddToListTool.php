<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\Checklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_to_list')]
#[Description('Ajoute un item à une liste existante (ex : "ajoute du lait à la liste courses"). Ne crée jamais de liste silencieusement.')]
class AddToListTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()->description('Nom approximatif de la liste.')->required(),
            'item' => $schema->string()->description("Le texte de l'item à ajouter.")->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'list' => ['required', 'string'],
            'item' => ['required', 'string'],
        ]);

        $checklist = $this->resolver->disambiguate(
            $this->resolver->checklists($validated['list']),
            fn ($c): string => $c->name,
            "Plusieurs listes correspondent à « {$validated['list']} »",
        );

        if ($checklist === null) {
            $existing = Checklist::pluck('name')->implode(', ');

            return Response::error("Aucune liste ne correspond à « {$validated['list']} ». Listes existantes : {$existing}.");
        }

        $checklist->items()->create([
            'content' => $validated['item'],
            'position' => $checklist->items()->count(),
        ]);

        return Response::text("Ajouté « {$validated['item']} » à {$checklist->name}.");
    }
}
