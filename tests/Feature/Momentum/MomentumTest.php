<?php

use App\Models\Goal;
use App\Models\Task;
use App\Models\User;
use App\Support\Momentum\MomentumCalculator;
use App\Support\Momentum\MomentumSettings;
use App\Support\Palette;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(fn () => Carbon::setTestNow());

test('the streak counts consecutive days with at least one completed task', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

    foreach ([0, 1, 2] as $daysAgo) {
        Task::factory()->done()->create(['completed_at' => now()->subDays($daysAgo)]);
    }

    expect(app(MomentumCalculator::class)->streakDays())->toBe(3);
});

test('several tasks on the same day still count as one day', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

    Task::factory()->done()->create(['completed_at' => now()]);
    Task::factory()->done()->create(['completed_at' => now()->subHours(2)]);

    expect(app(MomentumCalculator::class)->streakDays())->toBe(1);
});

test('a streak survives a morning with nothing done yet', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

    Task::factory()->done()->create(['completed_at' => now()->subDay()]);
    Task::factory()->done()->create(['completed_at' => now()->subDays(2)]);

    expect(app(MomentumCalculator::class)->streakDays())->toBe(2);
});

test('a streak breaks after a full empty day', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

    Task::factory()->done()->create(['completed_at' => now()->subDays(2)]);
    Task::factory()->done()->create(['completed_at' => now()->subDays(3)]);

    expect(app(MomentumCalculator::class)->streakDays())->toBe(0);
});

test('the next tier is the first of 7, 30 and 100 above the current streak', function () {
    $calculator = app(MomentumCalculator::class);

    expect($calculator->nextStreakTier(0))->toBe(7)
        ->and($calculator->nextStreakTier(7))->toBe(30)
        ->and($calculator->nextStreakTier(40))->toBe(100)
        ->and($calculator->nextStreakTier(120))->toBeNull();
});

test('a gauge with no activity at all sits at its floor', function () {
    expect(app(MomentumCalculator::class)->gaugeLevel([]))->toBe(10);
});

test('a gauge loses 8 points per idle week, never below the floor', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

    // Une semaine chargée il y a onze semaines, puis plus rien : la décroissance
    // ramène la jauge au plancher.
    $dates = array_fill(0, 6, now()->subWeeks(11)->toDateString());

    expect(app(MomentumCalculator::class)->gaugeLevel($dates))->toBe(10);
});

test('a gauge rises with recent activity', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

    $quiet = app(MomentumCalculator::class)->gaugeLevel([now()->subWeeks(6)->toDateString()]);
    $busy = app(MomentumCalculator::class)->gaugeLevel([
        now()->subWeek()->toDateString(),
        now()->subWeek()->toDateString(),
        now()->toDateString(),
    ]);

    expect($busy)->toBeGreaterThan($quiet);
});

test('the band shows the two healthiest goals plus the one at risk', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

    $goals = collect(['A', 'B', 'C', 'D'])->mapWithKeys(
        fn (string $name): array => [$name => Goal::factory()->create(['title' => $name, 'status' => 'active'])]
    );

    // A et B avancent, C avance un peu, D n'a jamais rien reçu.
    foreach (range(1, 4) as $ignored) {
        Task::factory()->done()->create(['goal_id' => $goals['A']->id, 'completed_at' => now()]);
        Task::factory()->done()->create(['goal_id' => $goals['B']->id, 'completed_at' => now()]);
    }
    Task::factory()->done()->create(['goal_id' => $goals['C']->id, 'completed_at' => now()->subWeeks(4)]);

    $titles = app(MomentumCalculator::class)->bandGauges()->map(fn ($gauge): string => $gauge->goal->title)->all();

    expect($titles)->toHaveCount(3)
        ->and($titles)->toContain('A')
        ->and($titles)->toContain('B')
        ->and($titles)->toContain('D');
});

test('a gauge reads its tone from its level and only names an idle streak past two weeks', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-01 09:00:00'));

    $goal = Goal::factory()->create(['status' => 'active']);
    Task::factory()->done()->create(['goal_id' => $goal->id, 'completed_at' => now()->subWeeks(4)]);

    $gauge = app(MomentumCalculator::class)->bandGauges()->first();

    expect($gauge->tone())->toBe('wheat')
        ->and($gauge->idleLabel())->toBe('4 sem.');
});

test('a goal keeps the same season hue across renders', function () {
    $goal = Goal::factory()->create();

    expect($goal->color_slot)->toBe(Palette::slot($goal->id))
        ->and(Palette::SLOTS)->toContain($goal->color_slot);
});

test('turning the gauges off empties the status band', function () {
    $this->actingAs(User::factory()->create());

    $goal = Goal::factory()->create(['status' => 'active']);
    Task::factory()->done()->create(['goal_id' => $goal->id, 'completed_at' => now()]);

    expect(Livewire::test('pages::today')->get('gauges'))->toHaveCount(1);

    app(MomentumSettings::class)->update(gauges: false, streak: true, celebration: true);

    expect(Livewire::test('pages::today')->get('gauges'))->toHaveCount(0);
});

test('turning the streak off drops it to zero and neutralises the day title', function () {
    $this->actingAs(User::factory()->create());

    Task::factory()->done()->create(['completed_at' => now()]);

    app(MomentumSettings::class)->update(gauges: true, streak: false, celebration: true);

    $component = Livewire::test('pages::today');

    expect($component->get('streak'))->toBe(0)
        ->and($component->get('dayTitle'))->toBe("Aujourd'hui");
});

test('turning the celebration off hides a freshly closed goal', function () {
    $this->actingAs(User::factory()->create());

    Goal::factory()->create(['title' => 'Vendre l\'appartement', 'status' => 'done']);

    expect(Livewire::test('pages::today')->get('celebratedGoal'))->not->toBeNull();

    app(MomentumSettings::class)->update(gauges: true, streak: true, celebration: false);

    expect(Livewire::test('pages::today')->get('celebratedGoal'))->toBeNull();
});

test('every momentum switch is on until it is explicitly turned off', function () {
    $settings = app(MomentumSettings::class);

    expect($settings->gaugesEnabled())->toBeTrue()
        ->and($settings->streakEnabled())->toBeTrue()
        ->and($settings->celebrationEnabled())->toBeTrue();

    $settings->update(gauges: false, streak: false, celebration: false);

    expect($settings->gaugesEnabled())->toBeFalse()
        ->and($settings->streakEnabled())->toBeFalse()
        ->and($settings->celebrationEnabled())->toBeFalse();
});
