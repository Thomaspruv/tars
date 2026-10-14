<?php

use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(fn () => test()->actingAs(User::factory()->create()));

test('typing exposes one chip per recognized fragment, in reading order', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);

    $component = Livewire::test('quick-add')->set('query', 'Appeler le plombier demain 14h @Lilas p1');

    $chips = $component->get('chips');

    expect($chips)->toHaveCount(4);
    expect(array_column($chips, 'kind'))->toBe(['date', 'time', 'entity', 'priority']);
    expect(array_column($chips, 'prefix'))->toBe(['date', 'heure', '@', 'priorité']);
    expect(array_column($chips, 'tone'))->toBe(['mine', 'mine', 'neutral', 'mine']);
    expect($chips[2]['label'])->toBe('Appart Lilas');
});

test('the preview announces the title, when, the attachments and the priority', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Goal::factory()->create(['title' => 'Appart locatif']);

    $component = Livewire::test('quick-add')->set('query', 'Relancer le syndic demain 14h30 @Lilas #Appart p1');

    expect($component->get('preview'))->toBe(
        'Relancer le syndic · '.today()->addDay()->translatedFormat('D j M').' 14h30 · @Appart Lilas · #Appart locatif · P1'
    );
});

test('the preview says a recurrence in plain french', function () {
    $component = Livewire::test('quick-add')->set('query', 'TVA tous les mois p2');

    expect($component->get('kind'))->toBe('task');
    expect($component->get('preview'))->toBe('TVA · ↻ tous les mois · P2');
});

test('nothing recognized shows no chip at all', function () {
    expect(Livewire::test('quick-add')->set('query', 'Acheter du lait')->get('chips'))->toBe([]);
});

test('the announced kind follows the line, and tab cycles it', function () {
    $component = Livewire::test('quick-add')->set('query', 'Réunion compta vendredi 10h');

    expect($component->get('kind'))->toBe('event');
    expect($component->get('kindLabel'))->toBe('Événement');

    $component->call('cycleKind');
    expect($component->get('kind'))->toBe('note');

    $component->call('cycleKind');
    expect($component->get('kind'))->toBe('task');

    $component->call('cycleKind');
    expect($component->get('kind'))->toBe('event');
});

test('it posts the chosen type, not the guessed one', function () {
    Livewire::test('quick-add')
        ->set('query', 'Réunion compta vendredi 10h')
        ->call('submit');

    $event = Event::where('title', 'Réunion compta')->first();
    expect($event)->not->toBeNull();
    expect($event->starts_at->format('H:i'))->toBe('10:00');

    // Le même texte, mais j'ai dit « Note » : il part en inbox, préfixé.
    Livewire::test('quick-add')
        ->set('query', 'Réunion compta vendredi 10h')
        ->call('cycleKind')
        ->call('submit');

    expect(InboxItem::where('content', 'Note : Réunion compta vendredi 10h')->exists())->toBeTrue();
});

test('a recurrence posted as a task lands on the task itself', function () {
    Livewire::test('quick-add')
        ->set('query', 'TVA tous les mois p2')
        ->call('submit');

    expect(Task::where('title', 'TVA')->first()?->recurrence)->toBe('monthly');
});

test('a line announcing a note goes to the inbox as it is', function () {
    Goal::factory()->create(['title' => 'Lancer le site vitrine']);

    Livewire::test('quick-add')
        ->set('query', 'Idée : newsletter #Lancer le site vitrine')
        ->call('submit');

    expect(InboxItem::where('content', 'Idée : newsletter #Lancer le site vitrine')->exists())->toBeTrue();
    expect(Task::count())->toBe(0);
});

test('the palette says what it reads, what it will create and how to change it', function () {
    $html = Livewire::test('quick-add')->set('query', 'Appeler le plombier demain 14h30 p1')->html();

    expect($html)->toContain('Poser quelque chose')
        ->and($html)->toContain('Ça va créer')
        // Le type annoncé est en pousse : c'est l'agent qui parle.
        ->and($html)->toContain('text-(--ai)">Tâche')
        ->and($html)->toContain('⇥ pour changer le type')
        ->and($html)->toContain('Entrée pour poser');
});

test('an empty field invites instead of staying mute', function () {
    Livewire::test('quick-add')
        ->assertSee('je les reconnais pendant que tu écris', false)
        ->assertSee('Ça va créer');
});

test('a two-word name that resolves leaves nothing behind in the title', function () {
    Entity::factory()->create(['name' => 'SARL Alpha']);

    $component = Livewire::test('quick-add')->set('query', 'Réunion compta vendredi 10h @SARL Alpha');

    expect($component->get('preview'))->toContain('Réunion compta')
        ->and($component->get('preview'))->not->toContain('Alpha ·');
});

test('a two-word capture that resolves to nothing falls back to the first word alone', function () {
    $entity = Entity::factory()->create(['name' => 'Appart Lilas']);

    // « Jeudi » est une date, pas la suite du nom : seul « Lilas » est
    // consommé, et l'échéance est lue derrière.
    $component = Livewire::test('quick-add')->set('query', 'Relancer @Lilas Jeudi');

    expect($component->get('parsed')->entity?->id)->toBe($entity->id);
    expect($component->get('parsed')->title)->toBe('Relancer');
    expect($component->get('parsed')->date?->dayOfWeek)->toBe(Carbon::THURSDAY);
});

test('a task posted without a chosen type keeps falling back to the inbox', function () {
    Livewire::test('quick-add')
        ->set('query', 'Acheter du lait')
        ->call('submit');

    expect(InboxItem::where('content', 'Acheter du lait')->exists())->toBeTrue()
        ->and(Task::count())->toBe(0);

    // Mais si je dis « Tâche », c'est une tâche — même sans rien de reconnu.
    Livewire::test('quick-add')
        ->set('query', 'Acheter du pain')
        ->call('cycleKind')
        ->call('cycleKind')
        ->call('cycleKind')
        ->call('submit');

    expect(Task::where('title', 'Acheter du pain')->exists())->toBeTrue();
});

test('the chosen type is forgotten once the capture is posted', function () {
    $component = Livewire::test('quick-add')
        ->set('query', 'Réunion compta vendredi 10h')
        ->call('cycleKind')
        ->call('submit');

    expect($component->get('typeOverride'))->toBe('');
    expect($component->get('query'))->toBe('');
});
