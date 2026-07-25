<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\AmbiguousToolCall;
use App\Mcp\Support\NameResolver;
use App\Models\Checklist;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_list')]
#[Description('Retourne le contenu d\'une liste par nom approximatif, avec ce qui est déjà coché.')]
class GetListTool extends Tool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'list' => $schema->string()->description('Nom approximatif de la liste.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->getList($request);
        } catch (AmbiguousToolCall $e) {
            return Response::text($e->getMessage());
        }
    }

    private function getList(Request $request): Response
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

        $items = $checklist->items()->orderBy('position')->get();

        if ($items->isEmpty()) {
            return Response::text("{$checklist->name} est vide.");
        }

        $lines = $items->map(fn ($item): string => $item->content.($item->checked_at ? ' (fait)' : ''))->implode(', ');

        return Response::text("{$checklist->name} : {$lines}.");
    }
}
