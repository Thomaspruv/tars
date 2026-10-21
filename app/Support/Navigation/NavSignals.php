<?php

namespace App\Support\Navigation;

use App\Enums\BrainSuggestionStatus;
use App\Enums\MissionStatus;
use App\Enums\OpenDecisionStatus;
use App\Models\BrainSuggestion;
use App\Models\InboxItem;
use App\Models\Mission;
use App\Models\OpenDecision;
use App\Models\QuestionnaireRun;
use App\Models\Review;

/**
 * Ce qui attend derrière le menu, compté une seule fois par requête.
 *
 * La barre latérale et la barre d'onglets lisent les mêmes chiffres : la
 * mémoïsation est posée sur la requête courante, le seul porteur qui meurt
 * vraiment avec elle — ni `once()` ni le conteneur ne sont rejoués entre deux
 * requêtes d'un même test, et deux comptes identiques seraient alors du passé
 * affiché comme du présent. Le vert est le compte de l'agent, l'abricot le
 * mien.
 *
 * @phpstan-type Count array{count: int, tone: 'agent'|'mine', display: 'count'|'dot', sr: string}
 */
final class NavSignals
{
    private const MEMO = 'nav.signals';

    /**
     * @return array<string, Count>
     */
    public static function all(): array
    {
        $request = request();

        if (! $request->attributes->has(self::MEMO)) {
            $request->attributes->set(self::MEMO, self::compute());
        }

        /** @var array<string, Count> */
        return $request->attributes->get(self::MEMO);
    }

    /**
     * @return array<string, Count>
     */
    private static function compute(): array
    {
        $review = Review::whereNull('completed_at')->exists() ? 1 : 0;
        $bilan = QuestionnaireRun::where('status', 'pending')->count();

        return [
            'inbox' => [
                'count' => InboxItem::whereNull('processed_at')->count(),
                'tone' => 'agent',
                'display' => 'count',
                'sr' => 'en attente dans l\'inbox',
            ],
            'decisions' => [
                'count' => OpenDecision::where('status', OpenDecisionStatus::Open->value)->count(),
                'tone' => 'mine',
                'display' => 'count',
                'sr' => 'à trancher',
            ],
            'curator' => [
                'count' => BrainSuggestion::where('status', BrainSuggestionStatus::Pending->value)->count(),
                'tone' => 'agent',
                'display' => 'count',
                'sr' => 'proposition du curateur',
            ],
            'bilan' => [
                'count' => $bilan,
                'tone' => 'agent',
                'display' => 'count',
                'sr' => 'bilan à remplir',
            ],
            'missions' => [
                'count' => Mission::whereIn('status', [MissionStatus::Queued->value, MissionStatus::Running->value])->count(),
                'tone' => 'agent',
                'display' => 'dot',
                'sr' => 'mission en cours',
            ],
            'review' => [
                'count' => $review,
                'tone' => 'agent',
                'display' => 'dot',
                'sr' => 'une revue vous attend',
            ],
            'revue' => [
                'count' => $review + $bilan,
                'tone' => 'agent',
                'display' => 'dot',
                'sr' => 'une revue ou un bilan vous attend',
            ],
        ];
    }

    /**
     * @return Count|null
     */
    public static function get(?string $key): ?array
    {
        if ($key === null) {
            return null;
        }

        $signal = self::all()[$key] ?? null;

        return ($signal !== null && $signal['count'] > 0) ? $signal : null;
    }
}
