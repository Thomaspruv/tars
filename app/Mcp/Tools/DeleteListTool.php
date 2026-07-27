<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\Checklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('delete_list')]
#[Description('Supprime définitivement une liste et tous ses items, résolue par nom approximatif.')]
class DeleteListTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()->description('Nom approximatif de la liste à supprimer.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['list' => ['required', 'string']]);

        $checklist = $this->resolver->disambiguate(
            $this->resolver->checklists($validated['list']),
            fn ($c): string => $c->name,
            "Plusieurs listes correspondent à « {$validated['list']} »",
        );

        if ($checklist === null) {
            $existing = Checklist::pluck('name')->implode(', ');

            return Response::error("Aucune liste ne correspond à « {$validated['list']} ». Listes existantes : {$existing}.");
        }

        $name = $checklist->name;
        $checklist->delete();

        return Response::text("Liste « {$name} » supprimée.");
    }
}
