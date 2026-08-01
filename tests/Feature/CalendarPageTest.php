<?php

use App\Models\Event;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 8, 1));
    $this->actingAs(User::factory()->create());
});

afterEach(fn () => Carbon::setTestNow());

test('renders events on the currently displayed month', function () {
    Event::factory()->create(['title' => 'Réunion équipe', 'starts_at' => Carbon::create(2026, 8, 15, 10, 0)]);

    Livewire::test('pages::calendar')->assertSee('Réunion équipe');
});

test('previousMonth and nextMonth navigate across months', function () {
    Event::factory()->create(['title' => 'Événement juillet', 'starts_at' => Carbon::create(2026, 7, 10, 9, 0)]);

    Livewire::test('pages::calendar')
        ->assertDontSee('Événement juillet')
        ->assertSet('month', '2026-08')
        ->call('previousMonth')
        ->assertSet('month', '2026-07')
        ->assertSee('Événement juillet')
        ->call('nextMonth')
        ->assertSet('month', '2026-08')
        ->assertDontSee('Événement juillet');
});

test('goToToday resets the displayed month', function () {
    Livewire::test('pages::calendar')
        ->call('nextMonth')
        ->assertSet('month', '2026-09')
        ->call('goToToday')
        ->assertSet('month', '2026-08');
});

test('does not show tasks on the calendar', function () {
    Task::factory()->create(['title' => 'Tâche fantôme', 'scheduled_date' => Carbon::create(2026, 8, 15)]);

    Livewire::test('pages::calendar')->assertDontSee('Tâche fantôme');
});
