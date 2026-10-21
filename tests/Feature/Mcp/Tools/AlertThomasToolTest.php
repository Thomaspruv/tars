<?php

use App\Enums\WatchEnded;
use App\Jobs\SendAlertJob;
use App\Mcp\Servers\TarsServer;
use App\Mcp\Tools\AlertThomasTool;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Support\Alerts\AlertSettings;
use App\Support\Mcp\CallingMission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

test('an alert reaches the webhook, and stays in Tars', function () {
    app(AlertSettings::class)->setWebhookUrl('https://hermes.example/alerte');

    TarsServer::tool(AlertThomasTool::class, [
        'message' => 'Des places viennent de se libérer pour le marathon de Lille.',
        'link' => 'https://marathon.example/inscription',
    ])->assertOk()->assertSee('Thomas est prévenu.');

    Queue::assertPushed(SendAlertJob::class, fn (SendAlertJob $job) => str_contains($job->message, 'marathon de Lille'));
});

test('without a webhook, the alert is kept and Thomas is told it will wait', function () {
    TarsServer::tool(AlertThomasTool::class, ['message' => 'Quelque chose est arrivé.'])
        ->assertOk()
        ->assertSee("aucun webhook d'alerte n'est configuré");
});

/**
 * Chaque mission reçoit une config MCP écrite pour elle seule, avec son numéro
 * en en-tête : c'est ce qui permet à une alerte de savoir de quelle veille elle
 * vient, et donc de l'arrêter.
 */
test('an alert from a watching agent ends the watch and lands in its thread', function () {
    $mission = Mission::factory()->succeeded()->watching(30)->create();

    // L'en-tête que Tars écrit dans la config MCP de cette mission-là.
    request()->headers->set(CallingMission::HEADER, (string) $mission->id);

    TarsServer::tool(AlertThomasTool::class, [
        'message' => 'Des places se sont libérées.',
        'link' => 'https://marathon.example/inscription',
    ])->assertOk();

    $mission->refresh();

    expect($mission->isWatching())->toBeFalse()
        ->and($mission->watch_ended_reason)->toBe(WatchEnded::Found);

    $alert = MissionEvent::where('mission_id', $mission->id)->where('type', 'alert')->sole();

    expect($alert->text)->toBe('Des places se sont libérées.')
        ->and($alert->payload['link'])->toBe('https://marathon.example/inscription');
});

test('an alert from Hermes, who has no mission, is delivered all the same', function () {
    app(AlertSettings::class)->setWebhookUrl('https://hermes.example/alerte');

    TarsServer::tool(AlertThomasTool::class, ['message' => 'Rappel : ton train part dans une heure.'])
        ->assertOk();

    expect(MissionEvent::count())->toBe(0);

    Queue::assertPushed(SendAlertJob::class, fn (SendAlertJob $job) => $job->missionId === null);
});

test('a link that is not one is refused', function () {
    TarsServer::tool(AlertThomasTool::class, [
        'message' => 'Quelque chose.',
        'link' => 'pas une url',
    ])->assertHasErrors();
});

test('the webhook carries what the receiver needs to route it', function () {
    Queue::fake([]);
    Http::fake(['hermes.example/*' => Http::response('', 200)]);

    app(AlertSettings::class)->setWebhookUrl('https://hermes.example/alerte');

    $mission = Mission::factory()->succeeded()->create();

    (new SendAlertJob('Des places se sont libérées.', $mission->id, 'https://marathon.example'))
        ->handle(app(AlertSettings::class));

    Http::assertSent(function ($request) use ($mission) {
        $body = $request->data();

        return $body['source'] === 'tars'
            && $body['message'] === 'Des places se sont libérées.'
            && $body['mission_id'] === $mission->id
            && $body['mission_url'] === route('missions.show', $mission)
            && $body['agent'] === $mission->teamAgent->name;
    });
});

test('a webhook that refuses does not take the alert down with it', function () {
    Queue::fake([]);
    Http::fake(['hermes.example/*' => Http::response('nope', 500)]);

    app(AlertSettings::class)->setWebhookUrl('https://hermes.example/alerte');

    // L'alerte reste dans Tars quoi qu'il arrive : le webhook est un bonus,
    // pas le seul endroit où elle existe.
    (new SendAlertJob('Quelque chose.'))->handle(app(AlertSettings::class));
})->throwsNoExceptions();
