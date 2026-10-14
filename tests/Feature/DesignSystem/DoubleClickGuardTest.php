<?php

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

/**
 * Un aller-retour Livewire dure ~250 ms. Sans verrou, un deuxième clic pendant
 * ce délai renvoie un toggle qui annule le premier : la case se coche puis se
 * décoche, et on croit avoir cliqué deux fois.
 *
 * Le verrou est côté client (`wire:loading.attr="disabled"`), donc on ne peut
 * pas l'exercer depuis PHP — mais on peut vérifier qu'il est bien émis, et
 * surtout qu'il cible *cette* action-là et pas le composant entier : un verrou
 * trop large gèlerait toutes les autres cases pendant la requête.
 */
function assertGuarded(string $html, string $action): void
{
    expect($html)->toContain('wire:target="'.$action.'"')
        ->and($html)->toContain('wire:loading.attr="disabled"');
}

beforeEach(fn () => test()->actingAs(User::factory()->create()));

test("la case d'une tâche du jour est verrouillée pendant sa propre requête", function () {
    // La première tâche du jour est isolée dans la carte « La suivante » : il
    // en faut une seconde pour qu'une ligne cochable soit rendue.
    Task::factory()->create(['scheduled_date' => today()]);
    $task = Task::factory()->create(['scheduled_date' => today()]);

    assertGuarded(Livewire::test('pages::today')->html(), "toggleTask({$task->id})");
});

test("la case d'une tâche est verrouillée sur l'écran Tâches", function () {
    $task = Task::factory()->create(['scheduled_date' => today()]);

    assertGuarded(Livewire::test('pages::tasks')->html(), "toggleTask({$task->id})");
});

test("l'article d'une liste est verrouillé pendant sa propre requête", function () {
    $list = Checklist::factory()->create();
    $item = ChecklistItem::factory()->create(['list_id' => $list->id]);

    assertGuarded(Livewire::test('pages::lists')->html(), "toggleItem({$item->id})");
});

test("l'article d'une liste épinglée est verrouillé sur Aujourd'hui", function () {
    $list = Checklist::factory()->create(['is_pinned' => true]);
    $item = ChecklistItem::factory()->create(['list_id' => $list->id]);

    assertGuarded(Livewire::test('pages::today')->html(), "toggleListItem({$item->id})");
});

test('un jalon et une tâche sont verrouillés sur la fiche objectif', function () {
    $goal = Goal::factory()->create();
    $milestone = Milestone::factory()->create(['goal_id' => $goal->id]);
    $task = Task::factory()->create(['goal_id' => $goal->id]);

    $html = Livewire::test('pages::goals.show', ['goal' => $goal])->html();

    assertGuarded($html, "toggleMilestone({$milestone->id})");
    assertGuarded($html, "toggleTask({$task->id})");
});

test('une tâche est verrouillée sur la fiche entité', function () {
    $entity = Entity::factory()->create();
    $task = Task::factory()->create(['entity_id' => $entity->id]);

    assertGuarded(Livewire::test('pages::entities.show', ['entity' => $entity])->html(), "toggleTask({$task->id})");
});

test('deux tâches différentes portent deux verrous distincts', function () {
    $first = Task::factory()->create(['scheduled_date' => today()]);
    $second = Task::factory()->create(['scheduled_date' => today()]);

    $html = Livewire::test('pages::tasks')->html();

    // Le verrou ne doit jamais viser le composant entier, sinon cocher une
    // tâche gèlerait toutes les autres cases de l'écran.
    expect($html)->toContain('wire:target="toggleTask('.$first->id.')"')
        ->and($html)->toContain('wire:target="toggleTask('.$second->id.')"');
});
