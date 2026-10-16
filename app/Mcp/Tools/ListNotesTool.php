<?php

namespace App\Mcp\Tools;

use App\Mcp\Support\NameResolver;
use App\Models\BrainDocument;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\Schema\NoteType;
use App\Support\QuickAdd\QuickAddParser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list_notes')]
#[Description('Liste les notes du cerveau, filtrables par type, projet, entité, statut et date. Pour retrouver une note sans en connaître le titre exact.')]
class ListNotesTool extends LoggedTool
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly NameResolver $resolver = new NameResolver,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_map(fn (NoteType $type): string => $type->value, NoteType::cases()))->description('Le type de note.'),
            'project' => $schema->string()->description('Slug du projet.'),
            'entity' => $schema->string()->description("Nom approximatif d'une entité ancrée."),
            'status' => $schema->string()->enum(['active', 'paused', 'done', 'archived'])->description('Le statut.'),
            'since' => $schema->string()->description('Date en français : "lundi", "01/10", "la semaine dernière".'),
            'limit' => $schema->integer()->description('Nombre maximum de notes (20 par défaut).'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string'],
            'project' => ['nullable', 'string'],
            'entity' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,paused,done,archived'],
            'since' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if (! $this->settings->isConfigured()) {
            return Response::error("Le vault du cerveau n'est pas configuré.");
        }

        $query = BrainDocument::query();

        if (! empty($validated['type'])) {
            $query->where('note_type', $validated['type']);
        }

        if (! empty($validated['project'])) {
            $query->where('project_slug', $validated['project']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['entity'])) {
            $entity = $this->resolver->disambiguate(
                $this->resolver->entities($validated['entity']),
                fn ($candidate): string => $candidate->name,
                "Plusieurs entités correspondent à « {$validated['entity']} »",
            );

            if ($entity === null) {
                return Response::error("Aucune entité ne correspond à « {$validated['entity']} ».");
            }

            $query->whereHas('entities', fn ($entities) => $entities->where('entities.id', $entity->id));
        }

        if (! empty($validated['since'])) {
            $since = (new QuickAddParser)->parse($validated['since'])->date;

            if ($since === null) {
                return Response::error("Je n'ai pas compris la date « {$validated['since']} ».");
            }

            $query->where(fn ($scoped) => $scoped->whereDate('updated_on', '>=', $since)->orWhereDate('mtime', '>=', $since));
        }

        $documents = $query
            ->orderByDesc('updated_on')
            ->orderByDesc('mtime')
            ->limit($validated['limit'] ?? 20)
            ->get();

        if ($documents->isEmpty()) {
            return Response::text('Aucune note ne correspond.');
        }

        $lines = $documents->map(function (BrainDocument $document): string {
            $type = $document->noteType()?->label() ?? '—';
            $updated = $document->updated_on?->toDateString() ?? $document->mtime?->toDateString() ?? '—';

            return "{$document->title} · {$type} · {$updated} · {$document->path}";
        })->implode("\n");

        return Response::text($lines);
    }
}
