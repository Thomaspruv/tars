<?php

use App\Models\BrainDocument;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Decision;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\Milestone;
use App\Models\Note;
use App\Models\Task;
use App\Support\Search\GlobalSearch;
use App\Support\Search\SearchHit;
use Illuminate\Support\Collection;

/**
 * @return Collection<int, SearchHit>
 */
function search(string $term, int $limit = 8): Collection
{
    return app(GlobalSearch::class)->for($term, $limit);
}

test('it stays silent until the term is worth searching', function () {
    Goal::factory()->create(['title' => 'Syndic']);

    expect(search('')->all())->toBe([])
        ->and(search('s')->all())->toBe([])
        ->and(search('  ')->all())->toBe([]);
});

test('it finds a goal and points at its own page', function () {
    $goal = Goal::factory()->create(['title' => 'Racheter les parts du syndic']);

    $hit = search('syndic')->firstWhere('kind', 'Objectif');

    expect($hit)->not->toBeNull()
        ->and($hit->label)->toBe('Racheter les parts du syndic')
        ->and($hit->url)->toBe(route('goals.show', $goal));
});

test('it finds an entity and labels its type in french', function () {
    $entity = Entity::factory()->create(['name' => 'SARL Alpha', 'type' => 'company']);

    $hit = search('alpha')->firstWhere('kind', 'Entité');

    expect($hit->label)->toBe('SARL Alpha')
        ->and($hit->hint)->toBe('Société')
        ->and($hit->url)->toBe(route('entities.show', $entity));
});

test('it sends a task to the task list filtered on the term', function () {
    $goal = Goal::factory()->create(['title' => 'Immobilier']);
    Task::factory()->create(['title' => 'Relancer le syndic', 'goal_id' => $goal->id]);

    $hit = search('syndic')->firstWhere('kind', 'Tâche');

    expect($hit->label)->toBe('Relancer le syndic')
        ->and($hit->hint)->toBe('Immobilier')
        ->and($hit->url)->toBe(route('tasks.index', ['search' => 'syndic']));
});

test('it sends a milestone to the page of its goal', function () {
    $goal = Goal::factory()->create(['title' => 'Immobilier']);
    Milestone::factory()->create(['title' => 'Signer chez le notaire', 'goal_id' => $goal->id]);

    $hit = search('notaire')->firstWhere('kind', 'Jalon');

    expect($hit->url)->toBe(route('goals.show', $goal->id));
});

test('it finds a decision by its content', function () {
    $decision = Decision::factory()->create(['content' => 'Garder le prestataire actuel']);

    $hit = search('prestataire')->firstWhere('kind', 'Décision');

    expect($hit->url)->toBe(route('decisions.show', $decision));
});

test('it sends a note to whatever it hangs from', function () {
    $goal = Goal::factory()->create();
    $entity = Entity::factory()->create();

    Note::factory()->create(['title' => 'Compte rendu godzilla', 'goal_id' => $goal->id, 'entity_id' => null]);
    Note::factory()->create(['title' => 'Devis godzilla', 'goal_id' => null, 'entity_id' => $entity->id]);

    $urls = search('godzilla')->where('kind', 'Note')->pluck('url');

    expect($urls)->toHaveCount(2)
        ->and($urls)->toContain(route('goals.show', $goal->id))
        ->and($urls)->toContain(route('entities.show', $entity->id));
});

test('it drops a note that hangs from nothing, since it has nowhere to land', function () {
    Note::factory()->create(['title' => 'Note orpheline godzilla', 'goal_id' => null, 'entity_id' => null]);

    expect(search('godzilla')->where('kind', 'Note'))->toBeEmpty();
});

test('it finds a list and counts what is in it', function () {
    $list = Checklist::factory()->create(['name' => 'Courses de Noel']);
    ChecklistItem::factory()->count(3)->create(['list_id' => $list->id]);

    $hit = search('noel')->firstWhere('kind', 'Liste');

    expect($hit->hint)->toBe('3 éléments')
        ->and($hit->url)->toBe(route('lists.index'));
});

test('it deep links a brain document by its path', function () {
    $document = BrainDocument::factory()->create(['title' => 'Fiscalite LMNP', 'path' => 'notes/fiscalite-lmnp.md']);

    $hit = search('lmnp')->firstWhere('kind', 'Cerveau');

    expect($hit->hint)->toBe('notes/fiscalite-lmnp.md')
        ->and($hit->url)->toBe(route('brain.index', ['path' => $document->path]));
});

test('it surfaces what is still waiting in the inbox, and nothing already sorted', function () {
    InboxItem::factory()->create(['content' => 'Rappeler le plombier godzilla']);
    InboxItem::factory()->create(['content' => 'Deja traite godzilla', 'processed_at' => now()]);

    $hits = search('godzilla')->where('kind', 'Inbox');

    expect($hits)->toHaveCount(1)
        ->and($hits->first()->hint)->toBe('pas encore trié');
});

test('a title match outranks a body-only match', function () {
    Goal::factory()->create(['title' => 'Autre chose', 'description' => 'On y parlera de fiscalite']);
    Goal::factory()->create(['title' => 'Fiscalite']);

    expect(search('fiscalite')->first()->label)->toBe('Fiscalite');
});

test('it honours the limit it is given', function () {
    Task::factory()->count(6)->create(['title' => 'Relancer godzilla']);
    Goal::factory()->count(4)->create(['title' => 'Objectif godzilla']);

    expect(search('godzilla', 3))->toHaveCount(3);
});

test('it searches bodies as well as titles', function () {
    Goal::factory()->create(['title' => 'Sans rapport', 'description' => 'Il faudra parler du godzilla']);

    expect(search('godzilla')->firstWhere('kind', 'Objectif'))->not->toBeNull();
});
