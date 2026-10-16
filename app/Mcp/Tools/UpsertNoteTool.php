<?php

namespace App\Mcp\Tools;

use App\Models\BrainDocument;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\NotePathResolver;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\Schema\NoteType;
use App\Support\Brain\VaultWriter;
use App\Support\QuickAdd\QuickAddParser;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('upsert_note')]
#[Description("Écrit une connaissance typée dans le cerveau — personne, organisation, réunion, note, décision, journal, veille, projet. C'est TARS qui calcule le chemin d'après le type : ne fournis jamais de chemin. Réécrire une note vivante existante la met à jour au lieu de la dupliquer.")]
class UpsertNoteTool extends LoggedTool
{
    /**
     * Thomas's profile, the archives and the skills are not Hermes's to write:
     * the first is hand-written, the second is the curator's doing, the third
     * is a folder of files rather than a note.
     */
    private const array FORBIDDEN_TYPES = [NoteType::Profil, NoteType::Archive, NoteType::Skill];

    public function __construct(
        private readonly BrainSettings $settings,
        private readonly NotePathResolver $paths,
        private readonly VaultWriter $writer,
        private readonly FrontmatterBuilder $frontmatter,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum($this->allowedTypes())->description('Le type de la note — il décide du dossier et du nommage.')->required(),
            'title' => $schema->string()->description('Le titre de la note.')->required(),
            'content' => $schema->string()->description('Le corps de la note, en markdown.')->required(),
            'date' => $schema->string()->description('Date en français : "hier", "vendredi", "16/10". Par défaut aujourd\'hui.'),
            'status' => $schema->string()->enum(['active', 'paused', 'done', 'archived'])->description('Requis pour project, veille et a-traiter.'),
            'entity' => $schema->array()->items($schema->string())->description('Noms des entités à ancrer (personnes, organisations…).'),
            'goal' => $schema->array()->items($schema->string())->description('Noms des objectifs à ancrer.'),
            'tags' => $schema->array()->items($schema->string())->description('Mots-clés libres.'),
            'subject' => $schema->string()->description('Le sujet de veille, pour une note de type veille.'),
            'related' => $schema->array()->items($schema->string())->description('Titres de notes liées.'),
        ];
    }

    protected function execute(Request $request): Response
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', $this->allowedTypes())],
            'title' => ['required', 'string'],
            'content' => ['required', 'string'],
            'date' => ['nullable', 'string'],
            'status' => ['nullable', 'in:active,paused,done,archived'],
            'entity' => ['nullable', 'array'],
            'entity.*' => ['string'],
            'goal' => ['nullable', 'array'],
            'goal.*' => ['string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string'],
            'subject' => ['nullable', 'string'],
            'related' => ['nullable', 'array'],
            'related.*' => ['string'],
        ]);

        if (! $this->settings->isConfigured()) {
            return Response::error("Le vault du cerveau n'est pas configuré.");
        }

        $type = NoteType::from($validated['type']);
        $date = $this->resolveDate($validated['date'] ?? null);
        $relativePath = $this->paths->resolve($type, $validated['title'], $date, [
            'subject' => $validated['subject'] ?? null,
        ]);

        $existing = BrainDocument::where('path', $relativePath)->first();

        $frontmatter = $this->frontmatter->build(
            type: $type,
            title: $validated['title'],
            // A living note keeps the date it was born on; only `updated` moves.
            date: $this->originalDate($existing) ?? $date,
            source: 'hermes',
            extra: [
                'status' => $validated['status'] ?? null,
                'entity' => $validated['entity'] ?? null,
                'goal' => $validated['goal'] ?? null,
                'tags' => $validated['tags'] ?? null,
                'subject' => $validated['subject'] ?? null,
                'related' => $validated['related'] ?? null,
            ],
        );

        $result = $this->writer->write(
            $relativePath,
            $frontmatter,
            rtrim($validated['content'])."\n",
            "{$type->value} {$validated['title']}",
        );

        if (! $result->isWritten()) {
            return Response::error($result->message());
        }

        $verb = $existing ? 'mise à jour dans' : 'créée dans';

        return Response::text("Note « {$validated['title']} » {$verb} ".dirname($relativePath).'/.');
    }

    private function resolveDate(?string $date): ?CarbonInterface
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        return (new QuickAddParser)->parse($date)->date;
    }

    private function originalDate(?BrainDocument $existing): ?CarbonInterface
    {
        $date = $existing?->frontmatter['date'] ?? null;

        return is_string($date) ? Carbon::parse($date) : null;
    }

    /**
     * @return list<string>
     */
    private function allowedTypes(): array
    {
        return array_values(array_map(
            fn (NoteType $type): string => $type->value,
            array_filter(NoteType::cases(), fn (NoteType $type): bool => ! in_array($type, self::FORBIDDEN_TYPES, true)),
        ));
    }
}
