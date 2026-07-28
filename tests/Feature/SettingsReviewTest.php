<?php

use App\Models\User;
use App\Support\Review\ReviewSettings;
use Livewire\Livewire;

// Rendering pages::settings-app evaluates brainStatus(), which shells out to a
// real `git fetch` against BrainSettings::localPath(). Point it at a path with
// no .git so GitRepository::status() short-circuits to Unknown instead of
// hitting the developer's real local vault clone over the network.
beforeEach(fn () => config(['brain.local_path' => sys_get_temp_dir().'/settings-test-'.uniqid()]));

test('saves the weekly review time', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('reviewWeeklyTime', '19:30')
        ->call('saveReviewSettings')
        ->assertHasNoErrors();

    expect((new ReviewSettings)->weeklyTime())->toBe('19:30');
});

test('rejects an invalid weekly review time', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('reviewWeeklyTime', 'not-a-time')
        ->call('saveReviewSettings')
        ->assertHasErrors(['reviewWeeklyTime']);
});

test('saves the review notification email and toggle', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('reviewNotificationEmail', 'moi@exemple.com')
        ->set('reviewNotificationsEnabled', true)
        ->call('saveReviewSettings')
        ->assertHasNoErrors();

    $settings = new ReviewSettings;
    expect($settings->notificationEmail())->toBe('moi@exemple.com')
        ->and($settings->notificationsEnabled())->toBeTrue();
});

test('rejects an invalid review notification email', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('reviewNotificationEmail', 'not-an-email')
        ->call('saveReviewSettings')
        ->assertHasErrors(['reviewNotificationEmail']);
});

test('confirms the review settings save with an inline message', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('reviewWeeklyTime', '19:30')
        ->call('saveReviewSettings')
        ->assertSee('✓ Enregistré');
});

test('does not confirm the review settings save when validation fails', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')
        ->set('reviewWeeklyTime', 'not-a-time')
        ->call('saveReviewSettings')
        ->assertDontSee('✓ Enregistré');
});
