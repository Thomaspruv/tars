<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\AmbiguousToolCall;
use App\Mcp\Support\NameResolver;
use App\Models\Event;
use App\Support\QuickAdd\QuickAddParser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('add_event')]
#[Description("Crée un événement. La date accepte le langage naturel français (demain, mardi, 25/12), l'heure au format HH:MM (minuit par défaut).")]
class AddEventTool extends Tool
{
    public function __construct(private readonly NameResolver $resolver = new NameResolver) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description("Le titre de l'événement.")->required(),
            'date' => $schema->string()->description('Date en français : "demain", "mardi", "25/12"...')->required(),
            'time' => $schema->string()->pattern('^([01]\d|2[0-3]):[0-5]\d$')->description('Heure au format HH:MM. Minuit si absent.'),
            'entity' => $schema->string()->description("Nom approximatif de l'entité concernée."),
            'goal' => $schema->string()->description("Nom approximatif de l'objectif concerné."),
        ];
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->createEvent($request);
        } catch (AmbiguousToolCall $e) {
            return Response::text($e->getMessage());
        }
    }

    private function createEvent(Request $request): Response
    {
        $validated = $request->validate([
            'title' => ['required', 'string'],
            'date' => ['required', 'string'],
            'time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'entity' => ['nullable', 'string'],
            'goal' => ['nullable', 'string'],
        ]);

        $date = (new QuickAddParser)->parse($validated['date'])->date;

        if ($date === null) {
            return Response::error("Je n'ai pas compris la date « {$validated['date']} ».");
        }

        [$hour, $minute] = explode(':', $validated['time'] ?? '00:00');
        $startsAt = $date->copy()->setTime((int) $hour, (int) $minute);

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

        $goal = null;

        if (! empty($validated['goal'])) {
            $goal = $this->resolver->disambiguate(
                $this->resolver->goals($validated['goal']),
                fn ($g): string => $g->title,
                "Plusieurs objectifs correspondent à « {$validated['goal']} »",
            );

            if ($goal === null) {
                return Response::error("Aucun objectif ne correspond à « {$validated['goal']} ».");
            }
        }

        $event = Event::create([
            'title' => $validated['title'],
            'starts_at' => $startsAt,
            'entity_id' => $entity?->id,
            'goal_id' => $goal?->id,
        ]);

        return Response::text("Événement créé : {$event->title}, le {$startsAt->translatedFormat('d M à H:i')}.");
    }
}
