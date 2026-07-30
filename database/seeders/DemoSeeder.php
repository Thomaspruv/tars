<?php

namespace Database\Seeders;

use App\Enums\DecisionSource;
use App\Enums\EntityRelationType;
use App\Enums\EntityType;
use App\Models\Checklist;
use App\Models\Decision;
use App\Models\Entity;
use App\Models\EntityRelation;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\JournalEntry;
use App\Models\Note;
use App\Models\Questionnaire;
use App\Models\QuestionnaireRun;
use App\Models\Review;
use App\Models\Task;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Fills the purely in-app screens (Aujourd'hui, Objectifs, Entités, Listes,
     * Tâches, Inbox, Revue, Bilan de vie) with a coherent, realistic demo
     * dataset. Not tied to any test assertion, unlike ReferenceScenarioSeeder —
     * safe to tweak or re-run.
     *
     * Deliberately does NOT touch Cerveau (BrainDocument mirrors a real synced
     * vault on disk) or Agents (AiProvider/AgentConfig hold real API keys) —
     * seeding fake rows there risks colliding with real integrations rather
     * than just adding demo content.
     *
     * Run with: php artisan db:seed --class=DemoSeeder
     */
    public function run(): void
    {
        $studio = Entity::create(['name' => 'Studio Créatif', 'type' => EntityType::Company, 'context' => 'pro']);
        $maison = Entity::create(['name' => 'Maison Bordeaux', 'type' => EntityType::Property, 'context' => 'perso']);
        $clio = Entity::create(['name' => 'Clio Break', 'type' => EntityType::Vehicle, 'context' => 'perso']);
        $client = Entity::create(['name' => 'Client Dupont & Fils', 'type' => EntityType::Other, 'context' => 'pro']);
        $thomas = Entity::create(['name' => 'Thomas', 'type' => EntityType::Other, 'context' => 'perso']);

        EntityRelation::create(['entity_id' => $thomas->id, 'related_entity_id' => $studio->id, 'relation_type' => EntityRelationType::EmployedBy]);
        EntityRelation::create(['entity_id' => $thomas->id, 'related_entity_id' => $maison->id, 'relation_type' => EntityRelationType::OwnerOf]);

        $goalSite = Goal::create([
            'entity_id' => $studio->id,
            'title' => 'Lancer le site vitrine',
            'description' => 'Refonte et mise en ligne du site vitrine de Studio Créatif.',
            'target_date' => today()->addMonths(2),
        ]);
        $goalSite->milestones()->create(['title' => 'Choisir le CMS', 'status' => 'done', 'position' => 0]);
        $goalSite->milestones()->create(['title' => 'Rédiger les contenus', 'position' => 1]);
        $goalSite->milestones()->create(['title' => 'Mettre en ligne', 'position' => 2]);

        $goalToiture = Goal::create([
            'entity_id' => $maison->id,
            'title' => 'Refaire la toiture',
            'description' => 'Réfection complète de la toiture avant l\'hiver.',
            'target_date' => today()->addMonths(4),
        ]);
        $goalToiture->milestones()->create(['title' => 'Obtenir 3 devis', 'status' => 'done', 'position' => 0]);
        $goalToiture->milestones()->create(['title' => 'Choisir l\'artisan', 'position' => 1]);

        $goalSemi = Goal::create([
            'title' => 'Courir un semi-marathon',
            'description' => 'Courir un semi-marathon en moins de 2h.',
            'target_date' => today()->addMonths(5),
        ]);
        $goalSemi->milestones()->create(['title' => 'Courir 10km sans pause', 'status' => 'done', 'position' => 0]);
        $goalSemi->milestones()->create(['title' => 'Courir 15km sans pause', 'position' => 1]);

        // Overdue.
        Task::create(['title' => 'Relancer le client Dupont', 'entity_id' => $client->id, 'due_date' => today()->subDays(3), 'priority' => 'p1', 'status' => 'todo']);
        Task::create(['title' => 'Payer la facture EDF', 'entity_id' => $maison->id, 'due_date' => today()->subDay(), 'status' => 'todo']);

        // Due today.
        Task::create(['title' => 'Préparer le brief client', 'goal_id' => $goalSite->id, 'entity_id' => $studio->id, 'scheduled_date' => today(), 'priority' => 'p2', 'status' => 'todo']);
        Task::create(['title' => 'Sortie course à pied', 'goal_id' => $goalSemi->id, 'scheduled_date' => today(), 'priority' => 'p3', 'status' => 'todo']);

        // Done today.
        Task::create(['title' => 'Répondre aux emails', 'scheduled_date' => today(), 'status' => 'done', 'completed_at' => now()]);

        // Future / recurring / delegable / orphan.
        Task::create(['title' => 'Rédiger les mentions légales', 'goal_id' => $goalSite->id, 'scheduled_date' => today()->addDays(5), 'status' => 'todo']);
        Task::create(['title' => 'Loyer bureau', 'entity_id' => $studio->id, 'due_date' => today()->startOfMonth(), 'recurrence' => 'monthly:1', 'status' => 'todo']);
        Task::create(['title' => 'Relire le contrat prestataire', 'entity_id' => $studio->id, 'scheduled_date' => today()->addDay(), 'is_delegable' => true, 'status' => 'todo']);
        Task::create(['title' => 'Ranger le garage', 'scheduled_date' => today()->addDays(3), 'status' => 'todo']);

        $presentation = Task::create(['title' => 'Préparer la présentation client', 'goal_id' => $goalSite->id, 'entity_id' => $studio->id, 'scheduled_date' => today()->addDays(2), 'status' => 'todo']);
        $presentation->subtasks()->create(['title' => 'Faire les slides', 'status' => 'todo']);
        $presentation->subtasks()->create(['title' => 'Préparer la démo', 'status' => 'todo']);

        $courses = Checklist::create(['name' => 'Courses', 'is_pinned' => true]);
        $courses->items()->create(['content' => 'Lait', 'position' => 0]);
        $courses->items()->create(['content' => 'Pain', 'checked_at' => now(), 'position' => 1]);
        $courses->items()->create(['content' => 'Café', 'position' => 2]);

        $voyage = Checklist::create(['name' => 'Voyage Lisbonne']);
        $voyage->items()->create(['content' => 'Réserver l\'hôtel', 'position' => 0]);
        $voyage->items()->create(['content' => 'Billets d\'avion', 'position' => 1]);
        $voyage->items()->create(['content' => 'Assurance voyage', 'position' => 2]);

        $fournitures = Checklist::create(['name' => 'Fournitures bureau', 'entity_id' => $studio->id]);
        $fournitures->items()->create(['content' => 'Papier A4', 'position' => 0]);
        $fournitures->items()->create(['content' => 'Cartouches d\'encre', 'position' => 1]);

        Event::create(['title' => 'RDV client Dupont', 'starts_at' => today()->setTime(10, 0), 'entity_id' => $client->id]);
        Event::create(['title' => 'Visite toiture avec l\'artisan', 'starts_at' => today()->addDays(2)->setTime(9, 0), 'entity_id' => $maison->id, 'goal_id' => $goalToiture->id]);
        Event::create(['title' => 'Dîner anniversaire', 'starts_at' => today()->addDays(4)->setTime(20, 0)]);
        Event::create(['title' => 'Contrôle technique Clio', 'starts_at' => today()->addDays(6)->setTime(11, 30), 'entity_id' => $clio->id]);

        Note::create(['title' => 'Idées refonte site', 'content' => 'Ton plus direct, mise en avant des références clients, page tarifs simplifiée.', 'goal_id' => $goalSite->id]);
        Note::create(['title' => 'Historique travaux toiture', 'content' => 'Dernière réfection il y a 22 ans. Zinguerie à vérifier en priorité.', 'entity_id' => $maison->id]);
        Note::create(['title' => 'Lecture : Deep Work', 'content' => 'Bloquer des créneaux de concentration de 2h le matin, sans notifications.']);

        Decision::create(['content' => 'Choisir WordPress plutôt que du sur-mesure', 'context' => 'Délai serré, budget limité.', 'source' => DecisionSource::Conversation, 'goal_id' => $goalSite->id, 'decided_at' => now()->subDays(3)]);
        Decision::create(['content' => 'Reporter le semi-marathon de printemps à l\'automne', 'context' => 'Reprise de la course trop récente.', 'source' => DecisionSource::Conversation, 'goal_id' => $goalSemi->id, 'decided_at' => now()->subDays(10)]);

        InboxItem::create(['content' => 'On me propose un partenariat, à regarder']);
        InboxItem::create(['content' => 'Idée : newsletter mensuelle pour Studio Créatif']);
        InboxItem::create(['content' => 'Penser à réserver le camping pour l\'été']);

        Review::create([
            'type' => 'weekly',
            'period_start' => today()->subWeek()->startOfWeek(),
            'period_end' => today()->subWeek()->endOfWeek(),
            'generated_content' => "# Revue de la semaine\n\nBonne avancée sur le site vitrine, la toiture prend du retard.",
            'completed_at' => now()->subDays(6),
        ]);
        Review::create([
            'type' => 'weekly',
            'period_start' => today()->subWeeks(2)->startOfWeek(),
            'period_end' => today()->subWeeks(2)->endOfWeek(),
            'generated_content' => "# Revue de la semaine\n\nDémarrage du semi-marathon, premières sorties encourageantes.",
            'completed_at' => now()->subDays(13),
        ]);

        // Reuses an existing "Bilan mensuel" questionnaire if you already have
        // one configured, instead of creating a duplicate.
        $questionnaire = Questionnaire::firstOrCreate(
            ['name' => 'Bilan mensuel'],
            [
                'frequency' => 'monthly',
                'anchor' => '1',
                'questions' => [
                    ['text' => 'Satisfaction générale, de 1 à 10', 'type' => 'scale', 'scale_max' => 10],
                    ['text' => 'Un mot sur le mois écoulé', 'type' => 'text'],
                ],
                'is_active' => true,
            ],
        );

        $completedRun = QuestionnaireRun::create([
            'questionnaire_id' => $questionnaire->id,
            'due_date' => today()->subMonth(),
            'status' => 'completed',
            'completed_at' => now()->subMonth(),
        ]);
        $completedRun->answers()->create(['question_text' => 'Satisfaction générale, de 1 à 10', 'type' => 'scale', 'answer_numeric' => 7]);
        $completedRun->answers()->create(['question_text' => 'Un mot sur le mois écoulé', 'type' => 'text', 'answer_text' => 'Un mois productif malgré les imprévus sur la toiture.']);

        QuestionnaireRun::create([
            'questionnaire_id' => $questionnaire->id,
            'due_date' => today(),
            'status' => 'pending',
        ]);

        $yesterday = today()->subDay();
        JournalEntry::firstOrCreate(['date' => $yesterday], [
            'mood' => 7,
            'mood_label' => 'productif',
            'summary' => 'Bonne journée, avancée sur le brief client et la présentation.',
            'highlights' => ['Brief client bouclé', 'Sortie course à pied de 8km'],
            'source' => 'manual',
            'note_path' => 'TARS/Journal/'.$yesterday->format('Y').'/'.$yesterday->format('Y-m-d').'.md',
        ]);

        $twoDaysAgo = today()->subDays(2);
        JournalEntry::firstOrCreate(['date' => $twoDaysAgo], [
            'mood' => 5,
            'mood_label' => 'fatigué',
            'summary' => "Journée chargée, l'artisan pour la toiture a annulé son passage.",
            'highlights' => ['Replanifier la visite toiture'],
            'source' => 'manual',
            'note_path' => 'TARS/Journal/'.$twoDaysAgo->format('Y').'/'.$twoDaysAgo->format('Y-m-d').'.md',
        ]);
    }
}
