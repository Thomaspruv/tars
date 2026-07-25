<?php

namespace App\Mcp\Tools;

use App\Models\InboxItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('capture')]
#[Description("Capture une phrase brute dans l'inbox de TARS, sans aucune interprétation. À utiliser pour une idée ou un pense-bête à trier plus tard.")]
class CaptureTool extends LoggedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('Le texte brut à capturer.')->required(),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate(['text' => ['required', 'string']]);

        InboxItem::create(['content' => trim($validated['text'])]);

        return Response::text('Noté dans l\'inbox.');
    }
}
