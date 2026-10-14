<?php

namespace Database\Seeders;

use App\Enums\DecisionSource;
use App\Enums\OpenDecisionStatus;
use App\Models\Decision;
use App\Models\DecisionCriterion;
use App\Models\DecisionOption;
use App\Models\DecisionScore;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\OpenDecision;
use Illuminate\Database\Seeder;

class DecisionSeeder extends Seeder
{
    /**
     * Remplit l'écran Décisions : des questions ouvertes avec leur comparateur
     * à différents stades de remplissage (tout noté, à moitié noté, pas encore
     * noté, sans options du tout), une décision tranchée, une abandonnée, plus
     * quelques lignes de journal venues d'ailleurs que du comparateur.
     *
     * Se raccroche aux objectifs / entités déjà présents s'il y en a, sinon
     * laisse les liens vides — le seeder reste jouable sur une base vierge.
     *
     * Run with: php artisan db:seed --class=DecisionSeeder
     */
    public function run(): void
    {
        $goal = Goal::orderBy('id')->first();
        $entity = Entity::orderBy('id')->first();

        $this->seedFullyScored($goal?->id);
        $this->seedPartiallyScored($entity?->id);
        $this->seedUnscored();
        $this->seedWithoutOptions($goal?->id);
        $this->seedDecided($entity?->id);
        $this->seedDropped();
        $this->seedJournalOnlyEntries($goal?->id, $entity?->id);
    }

    /**
     * Comparateur complet : chaque case notée, une option qui se détache — le
     * cas où l'écran a quelque chose à dire.
     */
    private function seedFullyScored(?int $goalId): void
    {
        $decision = OpenDecision::create([
            'question' => 'Quel hébergeur pour le nouveau site ?',
            'context' => 'Le contrat actuel se termine fin du mois. Migration à prévoir dans tous les cas, autant choisir maintenant plutôt que de reconduire par défaut.',
            'status' => OpenDecisionStatus::Open,
            'goal_id' => $goalId,
            'due_date' => today()->addDays(9),
        ]);

        $this->buildGrid($decision, [
            'Prix' => 5,
            'Simplicité de déploiement' => 4,
            'Support en français' => 2,
        ], [
            'Laravel Cloud' => [3, 5, 3],
            'VPS auto-géré' => [5, 2, 1],
            'Hébergeur historique' => [2, 3, 5],
        ]);
    }

    /**
     * Comparateur à moitié rempli : une option encore vierge, donc sans score
     * du tout — c'est exactement ce que `scores()` renvoie en `null`.
     */
    private function seedPartiallyScored(?int $entityId): void
    {
        $decision = OpenDecision::create([
            'question' => 'Reprendre le bail du bureau ou passer en coworking ?',
            'context' => 'Préavis à poser 3 mois avant l\'échéance, donc décision attendue avant la fin du trimestre.',
            'status' => OpenDecisionStatus::Open,
            'entity_id' => $entityId,
            'due_date' => today()->subDays(4),
        ]);

        $this->buildGrid($decision, [
            'Coût mensuel' => 5,
            'Temps de trajet' => 3,
            'Calme' => 4,
        ], [
            'Garder le bureau' => [2, 4, 5],
            'Coworking centre-ville' => [4, 5, null],
            'Full télétravail' => [null, null, null],
        ]);
    }

    /**
     * Options et critères posés, rien de noté : le tableau attend qu'on
     * s'assoie devant.
     */
    private function seedUnscored(): void
    {
        $decision = OpenDecision::create([
            'question' => 'Où partir en vacances cet été ?',
            'context' => null,
            'status' => OpenDecisionStatus::Open,
            'due_date' => null,
        ]);

        $this->buildGrid($decision, [
            'Budget' => 4,
            'Dépaysement' => 3,
            'Facilité d\'organisation' => 2,
        ], [
            'Bretagne' => [null, null, null],
            'Sicile' => [null, null, null],
            'Rester à la maison' => [null, null, null],
        ]);
    }

    /**
     * Question posée sans rien en dessous : le premier état d'une décision
     * qu'on vient de capturer.
     */
    private function seedWithoutOptions(?int $goalId): void
    {
        OpenDecision::create([
            'question' => 'Faut-il recruter un alternant à la rentrée ?',
            'context' => 'À creuser une fois le budget du trimestre arrêté.',
            'status' => OpenDecisionStatus::Open,
            'goal_id' => $goalId,
            'due_date' => today()->addMonth(),
        ]);
    }

    /**
     * Une décision tranchée, montée comme le fait l'écran : ligne au journal,
     * option retenue, question fermée.
     */
    private function seedDecided(?int $entityId): void
    {
        $decision = OpenDecision::create([
            'question' => 'Quel comptable pour l\'exercice à venir ?',
            'context' => 'Le cabinet actuel a doublé ses honoraires sans prévenir.',
            'status' => OpenDecisionStatus::Open,
            'entity_id' => $entityId,
            'due_date' => today()->subDays(20),
        ]);

        $this->buildGrid($decision, [
            'Honoraires' => 4,
            'Réactivité' => 5,
            'Outils en ligne' => 3,
        ], [
            'Cabinet actuel' => [1, 3, 2],
            'Cabinet Martin' => [4, 5, 4],
            'Compta 100 % en ligne' => [5, 2, 5],
        ]);

        $chosen = $decision->options()->where('label', 'Cabinet Martin')->firstOrFail();

        $journalEntry = Decision::create([
            'content' => $decision->question.' → '.$chosen->label,
            'context' => $this->journalContext($decision, $chosen),
            'source' => DecisionSource::Arbitration,
            'goal_id' => $decision->goal_id,
            'entity_id' => $decision->entity_id,
            'decided_at' => now()->subDays(18),
        ]);

        $decision->forceFill([
            'status' => OpenDecisionStatus::Decided,
            'chosen_option_id' => $chosen->id,
            'decision_id' => $journalEntry->id,
            'closed_at' => now()->subDays(18),
        ])->save();
    }

    /**
     * Abandonnée : pas de ligne au journal, le tableau reste figé en l'état.
     */
    private function seedDropped(): void
    {
        $decision = OpenDecision::create([
            'question' => 'Changer de banque pro ?',
            'context' => 'Question rouverte trois fois sans jamais devenir urgente.',
            'status' => OpenDecisionStatus::Open,
        ]);

        $this->buildGrid($decision, [
            'Frais' => 4,
            'Douleur de la migration' => 5,
        ], [
            'Rester' => [2, 5],
            'Néobanque' => [5, 1],
        ]);

        $decision->forceFill([
            'status' => OpenDecisionStatus::Dropped,
            'closed_at' => now()->subDays(6),
        ])->save();
    }

    /**
     * Le journal ne se remplit pas qu'au comparateur : une revue et une
     * conversation y déposent aussi leurs lignes.
     */
    private function seedJournalOnlyEntries(?int $goalId, ?int $entityId): void
    {
        Decision::create([
            'content' => 'Repousser la refonte de l\'identité visuelle à l\'an prochain',
            'context' => 'Trop de chantiers ouverts en parallèle ce trimestre.',
            'source' => DecisionSource::Review,
            'goal_id' => $goalId,
            'decided_at' => now()->subDays(12),
        ]);

        Decision::create([
            'content' => 'Facturer les déplacements au forfait plutôt qu\'au réel',
            'context' => 'Moins de justificatifs à rassembler, écart négligeable sur l\'année.',
            'source' => DecisionSource::Conversation,
            'entity_id' => $entityId,
            'decided_at' => now()->subDays(30),
        ]);
    }

    /**
     * Monte le comparateur d'une décision : les critères avec leur poids, les
     * options, et la note de chaque case (`null` = case laissée vide).
     *
     * @param  array<string, int>  $criteria  label => poids
     * @param  array<string, array<int, int|null>>  $options  label => notes, dans l'ordre des critères
     */
    private function buildGrid(OpenDecision $decision, array $criteria, array $options): void
    {
        $criterionIds = [];

        foreach (array_values($criteria) as $position => $weight) {
            $criterionIds[] = DecisionCriterion::create([
                'open_decision_id' => $decision->id,
                'label' => array_keys($criteria)[$position],
                'weight' => $weight,
                'position' => $position,
            ])->id;
        }

        foreach (array_values($options) as $position => $scores) {
            $option = DecisionOption::create([
                'open_decision_id' => $decision->id,
                'label' => array_keys($options)[$position],
                'position' => $position,
            ]);

            foreach ($scores as $index => $score) {
                if ($score === null) {
                    continue;
                }

                DecisionScore::create([
                    'decision_option_id' => $option->id,
                    'decision_criterion_id' => $criterionIds[$index],
                    'score' => $score,
                ]);
            }
        }
    }

    /**
     * Même mise en forme que l'arbitrage depuis l'écran, pour que la ligne de
     * journal seedée soit indiscernable d'une vraie.
     */
    private function journalContext(OpenDecision $decision, DecisionOption $chosen): string
    {
        $decision->refresh()->load(['options.scores', 'criteria']);
        $scores = $decision->scores();

        $line = $decision->options
            ->map(fn (DecisionOption $option): string => $option->label.' : '.($scores[$option->id] ?? '—').' pts')
            ->implode(' · ');

        return implode("\n\n", array_filter([
            $decision->context,
            $line === '' ? null : 'Comparateur — '.$line,
            'Retenu : '.$chosen->label,
        ]));
    }
}
