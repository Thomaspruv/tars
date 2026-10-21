<?php

use App\Enums\WatchEnded;
use App\Jobs\RunMissionJob;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\SetWatchSentinelTool;
use App\Models\Mission;
use App\Models\User;
use App\Support\Mcp\CallingMission;
use App\Support\Missions\Sentinel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->actingAs(User::factory()->create());
});

function page(string $body): void
{
    Http::fake(['marathon.example/*' => Http::response($body)]);
}

/**
 * Le cœur de l'affaire : réveiller un agent coûte ~0,35 $, aller chercher une
 * page coûte zéro. Tant que la page ne bouge pas, personne n'est dérangé.
 */
test('an unchanged page wakes nobody', function () {
    page('<html><body>Complet</body></html>');

    $mission = Mission::factory()->succeeded()->watching(15)->create(['watch_url' => 'https://marathon.example/inscription']);
    $mission->forceFill(['watch_fingerprint' => (new Sentinel)->fingerprint('https://marathon.example/inscription')])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    $mission->refresh();

    expect($mission->watch_runs)->toBe(0)
        ->and($mission->watch_skips)->toBe(1)
        ->and($mission->watch_checked_at)->not->toBeNull()
        ->and($mission->watch_next_at?->isFuture())->toBeTrue();

    Queue::assertNothingPushed();
});

test('a page that changed wakes the agent, who alone can judge', function () {
    page('<html><body>Complet</body></html>');

    $mission = Mission::factory()->succeeded()->watching(15)->create(['watch_url' => 'https://marathon.example/inscription']);
    $mission->forceFill(['watch_fingerprint' => 'une-vieille-empreinte', 'watch_skips' => 4])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    $mission->refresh();

    expect($mission->watch_runs)->toBe(1)
        ->and($mission->watch_skips)->toBe(0)
        ->and($mission->watch_fingerprint)->not->toBe('une-vieille-empreinte');

    Queue::assertPushed(RunMissionJob::class);
});

test('the very first look always wakes the agent, so he can say what he sees', function () {
    page('<html><body>Complet</body></html>');

    $mission = Mission::factory()->succeeded()->watching(15)->create(['watch_url' => 'https://marathon.example/inscription']);

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->watch_runs)->toBe(1);
});

/**
 * Une page injoignable est peut-être l'événement lui-même — ou le signe que la
 * sentinelle regarde la mauvaise porte. Dans les deux cas, l'agent doit voir.
 */
test('a page that cannot be reached wakes the agent', function () {
    Http::fake(['marathon.example/*' => Http::response('parti', 503)]);

    $mission = Mission::factory()->succeeded()->watching(15)->create(['watch_url' => 'https://marathon.example/inscription']);
    $mission->forceFill(['watch_fingerprint' => 'une-empreinte'])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->watch_runs)->toBe(1);
});

/**
 * Sans battement de cœur, une sentinelle posée sur la mauvaise page garderait
 * le silence jusqu'à l'expiration de la veille.
 */
test('the agent is woken anyway, now and then, in case the sentinel watches the wrong door', function () {
    config()->set('missions.watch_heartbeat_skips', 3);
    page('<html><body>Complet</body></html>');

    $mission = Mission::factory()->succeeded()->watching(15)->create(['watch_url' => 'https://marathon.example/inscription']);
    $mission->forceFill([
        'watch_fingerprint' => (new Sentinel)->fingerprint('https://marathon.example/inscription'),
        'watch_skips' => 3,
    ])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->watch_runs)->toBe(1);
});

/**
 * Un jeton anti-CSRF ou une horloge cachée dans la page suffirait à déclarer un
 * changement à chaque passage — et la sentinelle ne garderait plus rien.
 */
test('noise that changes on every request is not a change', function () {
    $sentinel = new Sentinel;

    Http::fake(['marathon.example/*' => Http::sequence()
        ->push('<html><head><script>var t=1</script></head><body>Complet <input name="_token" value="a1b2c3d4e5f6a7b8c9d0"> 14:32</body></html>')
        ->push('<html><head><script>var t=2</script></head><body>Complet <input name="_token" value="99887766554433221100"> 16:05</body></html>'),
    ]);

    expect($sentinel->fingerprint('https://marathon.example/x'))
        ->toBe($sentinel->fingerprint('https://marathon.example/x'));
});

test('a real change is still seen through the noise', function () {
    $sentinel = new Sentinel;

    Http::fake(['marathon.example/*' => Http::sequence()
        ->push('<html><body>Complet</body></html>')
        ->push('<html><body>Inscriptions ouvertes</body></html>'),
    ]);

    expect($sentinel->fingerprint('https://marathon.example/x'))
        ->not->toBe($sentinel->fingerprint('https://marathon.example/x'));
});

test('an agent can post its own sentinel once it has found the page', function () {
    $mission = Mission::factory()->succeeded()->watching(15)->create();

    request()->headers->set(CallingMission::HEADER, (string) $mission->id);

    TarsServer::tool(SetWatchSentinelTool::class, [
        'url' => 'https://marathon.example/inscription',
    ])->assertOk()->assertSee('Sentinelle posée');

    expect($mission->refresh()->watch_url)->toBe('https://marathon.example/inscription');
});

test('a sentinel on a conversation that is not a watch is refused', function () {
    $mission = Mission::factory()->succeeded()->create();

    request()->headers->set(CallingMission::HEADER, (string) $mission->id);

    TarsServer::tool(SetWatchSentinelTool::class, [
        'url' => 'https://marathon.example/inscription',
    ])->assertHasErrors();
});

test('the screen says what the sentinel guards and how much it has saved', function () {
    $mission = Mission::factory()->succeeded()->watching(15)->create(['watch_url' => 'https://marathon.example/inscription']);
    $mission->forceFill(['watch_skips' => 37, 'watch_checked_at' => now()])->save();

    Livewire::test('pages::missions', ['mission' => $mission])
        ->assertSee('marathon.example/inscription')
        ->assertSee('37 vérifications sans rien déranger');
});

test('a watch with no sentinel behaves as before', function () {
    Http::fake();

    $mission = Mission::factory()->succeeded()->watching(15)->create();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->watch_runs)->toBe(1);
    Http::assertNothingSent();
});

test('a sentinel does not keep a dead watch alive', function () {
    page('<html><body>Complet</body></html>');

    $mission = Mission::factory()->succeeded()->watching(15)->create(['watch_url' => 'https://marathon.example/x']);
    $mission->forceFill(['watch_until' => now()->subHour()])->save();

    $this->artisan('missions:watch')->assertSuccessful();

    expect($mission->refresh()->watch_ended_reason)->toBe(WatchEnded::Expired);
    Http::assertNothingSent();
});
