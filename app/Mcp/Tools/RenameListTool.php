<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\Checklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('rename_list')]
#[Description('Renomme une liste existante, résolue par nom approximatif.')]
class RenameListTool extends LoggedTool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()->description('Nom approximatif de la liste à renommer.')->required(),
            'name' => $schema->string()->description('Le nouveau nom.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'list' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
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

        $oldName = $checklist->name;
        $checklist->update(['name' => $validated['name']]);

        return Response::text("Liste « {$oldName} » renommée en « {$checklist->name} ».");
    }
}
