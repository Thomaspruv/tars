<?php

namespace App\Mcp\Tools;

use App\Support\Journal\JournalDateResolver;
use App\Support\Journal\JournalWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('log_journal')]
#[Description("Enregistre l'entrée de journal du jour (ou d'hier). Si le mood n'est pas fourni, déduis-le du ton du récit (1 à 10) et annonce la valeur déduite dans ta confirmation. Un second appel le même jour s'ajoute à l'entrée existante, il ne l'écrase jamais.")]
class LogJournalTool extends LoggedTool
{
    public function __construct(
        private readonly JournalWriter $writer,
        private readonly JournalDateResolver $dates = new JournalDateResolver,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()->description('Le résumé de la journée, en français.')->required(),
            'mood' => $schema->integer()->description('Le mood ressenti, de 1 à 10. Si absent, déduis-le du ton du récit.'),
            'mood_label' => $schema->string()->description('Un mot ou une courte expression qualifiant le mood (libre).'),
            'highlights' => $schema->array()->items($schema->string())->description('Les moments marquants de la journée, un par élément.'),
            'date' => $schema->string()->description("« aujourd'hui » (défaut) ou « hier ». Aucune date future."),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'summary' => ['required', 'string'],
            'mood' => ['nullable', 'integer', 'between:1,10'],
            'mood_label' => ['nullable', 'string'],
            'highlights' => ['nullable', 'array'],
            'highlights.*' => ['string'],
            'date' => ['nullable', 'string'],
        ]);

        try {
            $date = $this->dates->resolveDate($validated['date'] ?? null);
        } catch (\Throwable) {
            return Response::error("Date non reconnue : « {$validated['date']} ».");
        }

        if ($date !== null && $date->isFuture()) {
            return Response::error('Impossible de journaliser une date future.');
        }

        $entry = $this->writer->write(
            summary: $validated['summary'],
            mood: $validated['mood'] ?? null,
            moodLabel: $validated['mood_label'] ?? null,
            highlights: $validated['highlights'] ?? [],
            date: $date,
            source: 'hermes',
        );

        $suffix = $entry->mood !== null ? " Mood {$entry->mood}/10." : '';

        return Response::text("Noté.{$suffix}");
    }
}
