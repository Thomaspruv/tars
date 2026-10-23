<?php

namespace App\Support\Brain;

use App\Enums\BrainSuggestionAction;
use App\Enums\BrainSuggestionStatus;
use App\Models\BrainDocument;
use App\Models\BrainSuggestion;
use App\Support\Brain\Schema\FrontmatterSchema;
use App\Support\Brain\Schema\NoteType;
use Illuminate\Support\Collection;

/**
 * The two health indicators of §A.2, plus what the conformance mission has
 * waiting. Read by `/cerveau`, the weekly review and `get_context` — one
 * computation, three readers.
 */
class BrainHealth
{
    public function __construct(private readonly VaultLayout $layout) {}

    public function report(): BrainHealthReport
    {
        $underContract = BrainDocument::underContract()->count();
        $conformant = BrainDocument::underContract()->where('is_conformant', true)->count();

        return new BrainHealthReport(
            documents: BrainDocument::count(),
            underContract: $underContract,
            conformant: $conformant,
            conformanceRate: $underContract > 0 ? (int) round(($conformant / $underContract) * 100) : 100,
            misplaced: $this->misplaced(['id', 'path', 'note_type'])->count(),
            pendingConformanceSuggestions: BrainSuggestion::query()
                ->where('status', BrainSuggestionStatus::Pending)
                ->whereNotNull('batch_id')
                ->count(),
            // `status != 'done'` alone would drop the NULL-status rows — which
            // is precisely every pre-contract note the sas still holds.
            sasNotes: BrainDocument::where('path', 'like', 'TARS/%')
                ->where('note_type', NoteType::ATraiter->value)
                ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'done'))
                ->count(),
        );
    }

    /**
     * Conformant notes sitting in the wrong folder for their type.
     *
     * @param  list<string>  $columns  narrow it when only the count is needed:
     *                                 this runs on every `/cerveau` render and every `get_context`.
     * @return Collection<int, BrainDocument>
     */
    public function misplaced(array $columns = ['*']): Collection
    {
        return BrainDocument::underContract()
            ->where('is_conformant', true)
            ->whereNotNull('note_type')
            ->get($columns)
            ->filter($this->isMisplaced(...))
            ->values();
    }

    /**
     * Une note conforme rangée dans le mauvais dossier *pour ce vault* — la
     * disposition étant une donnée du dépôt, c'est VaultLayout qui dit où le
     * type devrait vivre.
     */
    public function isMisplaced(BrainDocument $document): bool
    {
        $type = $document->noteType();
        $folder = explode('/', $document->path)[0];

        if ($type === null || FrontmatterSchema::isExempt($document->path) || FrontmatterSchema::isGenerated($document->path)) {
            return false;
        }

        // Le sas est une salle d'attente, les archives gardent le type qu'elles
        // avaient au classement : l'emplacement n'y dit rien du type.
        if (in_array($folder, $this->layout->unplacedFolders(), true)) {
            return false;
        }

        return $folder !== $this->layout->folderFor($type);
    }

    /**
     * @return list<string>
     */
    public static function conformanceActions(): array
    {
        return array_map(fn (BrainSuggestionAction $action): string => $action->value, [
            BrainSuggestionAction::Complete,
            BrainSuggestionAction::Move,
            BrainSuggestionAction::Merge,
            BrainSuggestionAction::Archive,
        ]);
    }
}
