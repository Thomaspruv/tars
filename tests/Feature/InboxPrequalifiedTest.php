<?php

use App\Enums\InboxConvertedType;
use App\Enums\InboxSuggestionStatus;
use App\Enums\SuggestionConfidence;
use App\Jobs\RunAgentCommandJob;
use App\Models\AgentConfig;
use App\Models\Entity;
use App\Models\Event;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => test()->actingAs(User::factory()->create()));

afterEach(fn () => Carbon::setTestNow());

function suggestionFor(string $content, SuggestionConfidence $confidence, array $overrides = []): InboxSuggestion
{
    $item = InboxItem::factory()->create(['content' => $content]);

    return InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'confidence' => $confidence,
        'suggested_fields' => ['title' => $content],
        ...$overrides,
    ]);
}

test('tout valider pose ce dont Hermès est sûr et laisse le reste', function () {
    $sure = suggestionFor('Relancer le syndic', SuggestionConfidence::High);
    $likely = suggestionFor('Payer la TVA', SuggestionConfidence::Medium);
    $unsure = suggestionFor('Le truc du garage', SuggestionConfidence::Low);

    Livewire::test('pages::inbox')
        ->assertSet('triageMessage', null)
        ->call('acceptAll');

    expect(Task::where('title', 'Relancer le syndic')->exists())->toBeTrue();
    expect(Task::where('title', 'Payer la TVA')->exists())->toBeTrue();
    expect(Task::where('title', 'Le truc du garage')->exists())->toBeFalse();

    expect($sure->fresh()->status)->toBe(InboxSuggestionStatus::Accepted);
    expect($likely->fresh()->status)->toBe(InboxSuggestionStatus::Accepted);
    expect($unsure->fresh()->status)->toBe(InboxSuggestionStatus::Pending);
    expect($unsure->inboxItem->fresh()->processed_at)->toBeNull();
});

test('le bouton tout valider ne compte que les propositions actionnables', function () {
    suggestionFor('Relancer le syndic', SuggestionConfidence::High);
    suggestionFor('Le truc du garage', SuggestionConfidence::Low);

    $component = Livewire::test('pages::inbox');

    expect($component->get('acceptableSuggestions'))->toHaveCount(1);
    $component->assertSee('Tout valider (1)');
});

test('le ✓ crée une fiche du type proposé, pas une tâche par défaut', function () {
    $item = InboxItem::factory()->create(['content' => 'Réunion compta']);
    $suggestion = InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => InboxConvertedType::Event,
        'confidence' => SuggestionConfidence::High,
        'suggested_fields' => ['title' => 'Réunion compta', 'starts_at' => '2026-10-20 10:00:00'],
    ]);

    Livewire::test('pages::inbox')->call('acceptSuggestion', $suggestion->id);

    $event = Event::where('title', 'Réunion compta')->first();

    expect($event)->not->toBeNull();
    expect($event->starts_at->format('Y-m-d H:i'))->toBe('2026-10-20 10:00');
    expect($item->fresh()->converted_to_type)->toBe(InboxConvertedType::Event);
    expect(Task::count())->toBe(0);
});

test('une proposition sûre montre son type et ses rattachements, pas les quatre issues', function () {
    $goal = Goal::factory()->create(['title' => 'Lancer le site vitrine']);
    Entity::factory()->create(['name' => 'SARL Alpha']);

    $item = InboxItem::factory()->create(['content' => 'Relancer le graphiste']);
    InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'suggested_type' => InboxConvertedType::Task,
        'confidence' => SuggestionConfidence::High,
        'reason' => 'Une action claire, rattachée au site.',
        'suggested_fields' => [
            'title' => 'Relancer le graphiste',
            'due_date' => '2026-10-20',
            'goal' => 'Lancer le site vitrine',
            'entity' => 'SARL Alpha',
        ],
    ]);

    $html = Livewire::test('pages::inbox')->html();

    expect($html)->toContain('Une action claire, rattachée au site.')
        ->and($html)->toContain('Valider')
        ->and($html)->toContain('Changer')
        ->and($html)->toContain('SARL Alpha')
        ->and($html)->toContain('--goal-'.$goal->color_slot)
        // La rangée des quatre types reste repliée derrière « Changer ».
        ->and($html)->toContain('changing');
});

test('un item sans proposition affiche la rangée des types', function () {
    InboxItem::factory()->create(['content' => 'Le truc du garage']);

    Livewire::test('pages::inbox')
        ->assertSee('Je ne suis pas sûr')
        ->assertSee('Tâche')
        ->assertSee('Événement')
        ->assertSee('Objectif')
        ->assertSee('Note')
        ->assertSee('Supprimer')
        ->assertDontSee('Tout valider');
});

test('une proposition à confiance basse rend la main sans disparaître', function () {
    suggestionFor('Le truc du garage', SuggestionConfidence::Low, ['reason' => "J'hésite entre une note et une tâche."]);

    Livewire::test('pages::inbox')
        ->assertSee('Je ne suis pas sûr')
        ->assertSee("J'hésite entre une note et une tâche.")
        ->assertSee('Supprimer');
});

test('choisir le type à la main écarte la proposition en attente', function () {
    $suggestion = suggestionFor('Le truc du garage', SuggestionConfidence::Low);

    Livewire::test('pages::inbox')->call('convertToNote', $suggestion->inbox_item_id);

    expect($suggestion->fresh()->status)->toBe(InboxSuggestionStatus::Rejected);
    expect($suggestion->inboxItem->fresh()->converted_to_type)->toBe(InboxConvertedType::Note);
});

test('ce qui est posé cette semaine reste listé et annulable 24 h', function () {
    // Jeudi : le lundi de la semaine est à trois jours, ce qui laisse de la
    // place pour une ligne posée hier.
    Carbon::setTestNow('2026-10-15 10:00:00');

    $recent = suggestionFor('Relancer le syndic', SuggestionConfidence::High);
    Livewire::test('pages::inbox')->call('acceptSuggestion', $recent->id);

    $old = suggestionFor('Vieille affaire', SuggestionConfidence::High);
    Livewire::test('pages::inbox')->call('acceptSuggestion', $old->id);
    $old->fresh()->forceFill(['created_at' => now()->subDays(2)])->save();

    $component = Livewire::test('pages::inbox');

    expect($component->get('settledThisWeek'))->toHaveCount(2);
    $component->assertSee('Cette semaine')
        ->assertSee('Relancer le syndic')
        ->assertSee('Vieille affaire')
        ->assertSee('Annuler');

    $component->call('cancelAutoApplied', $recent->id);

    expect(Task::where('title', 'Relancer le syndic')->exists())->toBeFalse();
    expect($recent->inboxItem->fresh()->processed_at)->toBeNull();
});

test('le trieur passe tout seul en arrivant, au plus une fois par dix minutes', function () {
    Queue::fake();
    Cache::forget('inbox:last-auto-triage');

    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    InboxItem::factory()->create(['content' => 'Le truc du garage']);

    Livewire::test('pages::inbox')->assertSet('triageMessage', 'Le trieur passe sur l\'inbox, ça arrive.');

    Queue::assertPushed(RunAgentCommandJob::class, 1);

    // Deuxième arrivée dans les dix minutes : le trieur ne repart pas.
    Livewire::test('pages::inbox')->assertSet('triageMessage', null);

    Queue::assertPushed(RunAgentCommandJob::class, 1);
});

test('le trieur ne part pas tout seul quand une proposition attend déjà', function () {
    Queue::fake();
    Cache::forget('inbox:last-auto-triage');

    AgentConfig::factory()->create(['agent_name' => 'triage', 'enabled' => true]);
    suggestionFor('Relancer le syndic', SuggestionConfidence::High);

    Livewire::test('pages::inbox')->assertSet('triageMessage', null);

    Queue::assertNothingPushed();
});
