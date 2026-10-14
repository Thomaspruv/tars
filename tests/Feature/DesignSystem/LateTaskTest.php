<?php

use App\Models\Task;
use App\Models\User;
use Livewire\Livewire;

/**
 * L'abricot est *mon* action — mon bouton, mon filtre actif, ma priorité. Il
 * n'a jamais servi à signaler un retard, et la pilule « depuis lundi » le
 * faisait quand même : une tâche en retard de trois jours prenait la couleur
 * de ce que je suis en train de faire. Le retard se lit maintenant au bord de
 * la case, en brique, et la date reste atténuée.
 */
beforeEach(fn () => test()->actingAs(User::factory()->create()));

test('une tâche en retard ne porte aucune pilule abricot', function () {
    // La plus en retard est isolée dans « La suivante » : il en faut une
    // seconde pour qu'une ligne de tâche soit rendue.
    Task::factory()->create(['due_date' => today()->subDays(5)]);
    $late = Task::factory()->create(['title' => 'Payer la TVA', 'due_date' => today()->subDays(3)]);

    $html = Livewire::test('pages::today')->html();

    expect($html)->not->toContain('depuis '.$late->due_date->translatedFormat('l'))
        ->and($html)->not->toContain('bg-(--acbg) px-[11px] py-1')
        // La date courte, en atténué, et le bord de case en brique.
        ->and($html)->toContain($late->due_date->translatedFormat('j M'))
        ->and($html)->toContain('border-(--dgr)');
});

test('une tâche du jour garde sa date en atténué, sans bord brique', function () {
    Task::factory()->create(['scheduled_date' => today()]);
    Task::factory()->create(['title' => 'Appeler le plombier', 'due_date' => today()]);

    $html = Livewire::test('pages::today')->html();

    expect($html)->toContain('aujourd\'hui')
        ->and($html)->not->toContain('border-(--dgr)');
});

test('la règle vaut aussi sur l\'écran Tâches, en densité compacte', function () {
    $late = Task::factory()->create(['title' => 'Payer la TVA', 'due_date' => today()->subDays(3)]);

    $html = Livewire::test('pages::tasks')->html();

    expect($html)->not->toContain('depuis '.$late->due_date->translatedFormat('l'))
        ->and($html)->toContain('border-(--dgr)');
});
