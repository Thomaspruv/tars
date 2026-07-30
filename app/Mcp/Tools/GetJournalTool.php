<?php

namespace App\Mcp\Tools;

use App\Models\JournalEntry;
use App\Support\Journal\JournalDateResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get_journal')]
#[Description("Relit le journal : par date précise (« aujourd'hui », « hier ») ou par période (« cette semaine », un mois nommé, ex. « juillet »). Renvoie mood et résumé, jamais d'identifiant.")]
class GetJournalTool extends LoggedTool
{
    public function __construct(private readonly JournalDateResolver $dates = new JournalDateResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->description("Une date précise : « aujourd'hui », « hier »..."),
            'period' => $schema->string()->description('Une période : « cette semaine », « juillet »...'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'date' => ['nullable', 'string'],
            'period' => ['nullable', 'string'],
        ]);

        if (! empty($validated['date'])) {
            try {
                $date = $this->dates->resolveDate($validated['date']);
            } catch (\Throwable) {
                return Response::error("Date non reconnue : « {$validated['date']} ».");
            }

            $entry = JournalEntry::whereDate('date', $date->toDateString())->first();

            if (! $entry) {
                return Response::text("Aucune entrée de journal le {$date->translatedFormat('d M')}.");
            }

            return Response::text($this->formatEntry($entry));
        }

        [$start, $end] = $this->dates->resolvePeriod($validated['period'] ?? null);

        $entries = JournalEntry::whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->get();

        if ($entries->isEmpty()) {
            return Response::text('Aucune entrée de journal sur cette période.');
        }

        return Response::text($entries->map(fn (JournalEntry $entry): string => $this->formatEntry($entry))->implode("\n"));
    }

    private function formatEntry(JournalEntry $entry): string
    {
        $moodPart = $entry->mood !== null
            ? ' — mood '.$entry->mood.'/10'.($entry->mood_label ? " ({$entry->mood_label})" : '')
            : '';

        return "{$entry->date->translatedFormat('d M')}{$moodPart} : {$entry->summary}";
    }
}
