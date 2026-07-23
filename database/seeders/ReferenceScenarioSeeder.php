<?php

namespace Database\Seeders;

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\LifeArea;
use App\Models\Task;
use Illuminate\Database\Seeder;

class ReferenceScenarioSeeder extends Seeder
{
    /**
     * Seed the reference scenario described in SPECS.md §6bis, used to validate
     * that every design decision holds up against a realistic personal setup.
     */
    public function run(): void
    {
        $pro = LifeArea::create(['name' => 'Pro', 'color' => '#53D6E8', 'position' => 0]);
        $immobilier = LifeArea::create(['name' => 'Immobilier', 'color' => '#9D8CF5', 'position' => 1]);
        $finances = LifeArea::create(['name' => 'Finances', 'color' => '#4ADE80', 'position' => 2]);
        $sante = LifeArea::create(['name' => 'Santé', 'color' => '#FBBF24', 'position' => 3]);
        LifeArea::create(['name' => 'Perso', 'color' => '#8A93A8', 'position' => 4]);

        $sarlAlpha = Entity::create([
            'life_area_id' => $pro->id,
            'name' => 'SARL Alpha',
            'type' => 'company',
            'context' => 'pro',
        ]);

        $sasBeta = Entity::create([
            'life_area_id' => $pro->id,
            'name' => 'SAS Beta',
            'type' => 'company',
            'context' => 'pro',
        ]);

        $appartLilas = Entity::create([
            'life_area_id' => $immobilier->id,
            'name' => 'Appart Lilas',
            'type' => 'property',
            'context' => 'perso',
        ]);

        $appartLyon = Entity::create([
            'life_area_id' => $immobilier->id,
            'name' => 'Appart Lyon',
            'type' => 'property',
            'context' => 'perso',
        ]);

        foreach ([$sarlAlpha, $sasBeta] as $company) {
            Task::create([
                'title' => 'TVA mensuelle',
                'entity_id' => $company->id,
                'due_date' => today()->startOfMonth()->addDays(19),
                'recurrence' => 'monthly:20',
                'status' => 'todo',
            ]);
        }

        foreach ([$appartLilas, $appartLyon] as $property) {
            Task::create([
                'title' => 'Quittance de loyer',
                'entity_id' => $property->id,
                'due_date' => today()->startOfMonth(),
                'recurrence' => 'monthly:1',
                'status' => 'todo',
            ]);

            Task::create([
                'title' => 'Taxe foncière',
                'entity_id' => $property->id,
                'due_date' => today()->startOfYear()->month(10)->day(15),
                'recurrence' => 'yearly:10-15',
                'status' => 'todo',
            ]);
        }

        $goal500k = Goal::create([
            'life_area_id' => $pro->id,
            'entity_id' => $sarlAlpha->id,
            'title' => '500k CA SARL Alpha',
            'description' => "Atteindre 500k€ de chiffre d'affaires annuel pour la SARL Alpha.",
            'target_date' => today()->endOfYear(),
        ]);

        Task::create([
            'title' => 'Préparer bilan',
            'goal_id' => $goal500k->id,
            'entity_id' => $sarlAlpha->id,
            'scheduled_date' => today(),
            'priority' => 'p1',
            'status' => 'todo',
        ]);

        $goalRenovation = Goal::create([
            'life_area_id' => $immobilier->id,
            'entity_id' => $appartLilas->id,
            'title' => 'Rénover SDB Lilas',
            'description' => 'Refaire entièrement la salle de bain de l\'appart Lilas.',
            'target_date' => today()->addMonths(3),
        ]);

        $goalRenovation->milestones()->create(['title' => 'Choisir le carreleur', 'position' => 0]);
        $goalRenovation->milestones()->create(['title' => 'Commander le carrelage', 'position' => 1]);

        $goalSemiMarathon = Goal::create([
            'life_area_id' => $sante->id,
            'title' => 'Semi-marathon',
            'description' => 'Courir un semi-marathon en moins de 2h.',
            'target_date' => today()->addMonths(6),
        ]);

        $goalSemiMarathon->milestones()->create(['title' => 'Courir 10km sans pause', 'position' => 0]);

        Task::create([
            'title' => 'Sortie longue',
            'goal_id' => $goalSemiMarathon->id,
            'scheduled_date' => today()->addDays(2),
            'priority' => 'p2',
            'status' => 'todo',
        ]);

        Task::create([
            'title' => 'Relancer le plombier',
            'entity_id' => $appartLilas->id,
            'due_date' => today()->addDay(),
            'priority' => 'p2',
            'status' => 'todo',
        ]);

        $courses = Checklist::create(['name' => 'Courses', 'is_pinned' => true]);
        ChecklistItem::create(['list_id' => $courses->id, 'content' => 'Lait', 'position' => 0]);
        ChecklistItem::create(['list_id' => $courses->id, 'content' => 'Pain', 'position' => 1]);

        $travauxSdb = Checklist::create(['name' => 'Travaux SDB', 'entity_id' => $appartLilas->id]);
        ChecklistItem::create(['list_id' => $travauxSdb->id, 'content' => 'Acheter le carrelage', 'position' => 0]);
        ChecklistItem::create(['list_id' => $travauxSdb->id, 'content' => 'Prendre RDV avec le carreleur', 'position' => 1]);

        Event::create([
            'title' => 'RDV banque',
            'starts_at' => today()->addDays(3)->setTime(14, 0),
            'entity_id' => $sarlAlpha->id,
        ]);

        InboxItem::create(['content' => 'On me propose un dîner jeudi, je dis oui ?']);
        InboxItem::create(['content' => 'Idée : comparer les plans d\'entraînement semi-marathon']);
    }
}
