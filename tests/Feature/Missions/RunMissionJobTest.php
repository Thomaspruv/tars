<?php

use App\Enums\MissionStatus;
use App\Jobs\RunMissionJob;
use App\Models\Mission;
use App\Models\MissionEvent;
use App\Models\TeamAgent;
use App\Support\Missions\ClaudeProcess;
use App\Support\Missions\MissionCommandBuilder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('missions.run_disk', 'local');
    config()->set('missions.claude_binary', '/bin/claude');
});

/**
 * Un flux fidèle à ce que le CLI écrit vraiment : l'init, du texte, une
 * délégation `Task`, ce que le sous-agent répond (avec son
 * `parent_tool_use_id`), le retour de l'outil, puis le résultat.
 *
 * @param  list<array<string, mixed>>  $events
 */
function stream(array $events): string
{
    return implode('', array_map(fn (array $event): string => json_encode($event)."\n", $events));
}

function fullStream(): string
{
    return stream([
        [
            'type' => 'system',
            'subtype' => 'init',
            'session_id' => 'abc',
            'mcp_servers' => [['name' => 'tars', 'status' => 'connected']],
        ],
        [
            'type' => 'assistant',
            'message' => ['content' => [['type' => 'text', 'text' => 'Je commence.']]],
            'parent_tool_use_id' => null,
            'timestamp' => '2026-10-16T10:00:00.000Z',
        ],
        [
            'type' => 'assistant',
            'message' => ['content' => [[
                'type' => 'tool_use',
                'id' => 'toolu_task',
                'name' => 'Task',
                'input' => ['description' => 'Ranger les notes', 'subagent_type' => 'documentaliste'],
            ]]],
            'parent_tool_use_id' => null,
            'timestamp' => '2026-10-16T10:00:01.000Z',
        ],
        [
            'type' => 'assistant',
            'message' => ['content' => [['type' => 'text', 'text' => 'Trois notes rangées.']]],
            'parent_tool_use_id' => 'toolu_task',
            'timestamp' => '2026-10-16T10:00:02.000Z',
        ],
        [
            'type' => 'user',
            'message' => ['content' => [[
                'type' => 'tool_result',
                'tool_use_id' => 'toolu_task',
                'content' => 'Trois notes rangées.',
            ]]],
            'parent_tool_use_id' => null,
            'timestamp' => '2026-10-16T10:00:03.000Z',
        ],
        [
            'type' => 'result',
            'subtype' => 'success',
            'is_error' => false,
            'result' => 'Trois notes rangées, rien à trancher.',
            'total_cost_usd' => 0.0412,
            'num_turns' => 4,
            'usage' => ['input_tokens' => 120, 'output_tokens' => 80, 'cache_read_input_tokens' => 400],
        ],
    ]);
}

test('the whole stream is stored, and the delegation keeps its parent', function () {
    Process::fake(['*' => Process::result(output: fullStream())]);

    $mission = Mission::factory()->create();

    (new RunMissionJob($mission->id))->handle(app(MissionCommandBuilder::class), app(ClaudeProcess::class));

    $events = MissionEvent::where('mission_id', $mission->id)->orderBy('seq')->get();

    expect($events->pluck('subtype')->all())
        ->toBe(['init', 'text', 'tool_use', 'text', 'tool_result', 'success']);

    $delegation = $events->firstWhere('subtype', 'tool_use');
    expect($delegation->tool_name)->toBe('Task')
        ->and($delegation->text)->toBe('Ranger les notes')
        ->and($delegation->tool_use_id)->toBe('toolu_task');

    // Ce que le sous-agent a dit porte l'appel qui l'a engendré : sans ça,
    // l'écran afficherait une liste plate au lieu d'une équipe.
    expect($events->where('subtype', 'text')->last()->parent_tool_use_id)->toBe('toolu_task')
        ->and($events->firstWhere('subtype', 'text')->parent_tool_use_id)->toBeNull();
});

test('the result event closes the mission with its real cost', function () {
    Process::fake(['*' => Process::result(output: fullStream())]);

    $mission = Mission::factory()->create();

    (new RunMissionJob($mission->id))->handle(app(MissionCommandBuilder::class), app(ClaudeProcess::class));

    $mission->refresh();

    expect($mission->status)->toBe(MissionStatus::Succeeded)
        ->and($mission->result)->toBe('Trois notes rangées, rien à trancher.')
        ->and((float) $mission->cost_usd)->toBe(0.0412)
        ->and($mission->num_turns)->toBe(4)
        ->and($mission->tokens_in)->toBe(520)
        ->and($mission->tokens_out)->toBe(80)
        ->and($mission->finished_at)->not->toBeNull()
        ->and($mission->pid)->not->toBeNull();
});

test('a failing result is a failed mission, with what it said', function () {
    Process::fake(['*' => Process::result(output: stream([[
        'type' => 'result',
        'subtype' => 'error_during_execution',
        'is_error' => true,
        'result' => 'Budget épuisé.',
    ]]))]);

    $mission = Mission::factory()->create();

    (new RunMissionJob($mission->id))->handle(app(MissionCommandBuilder::class), app(ClaudeProcess::class));

    expect($mission->refresh()->status)->toBe(MissionStatus::Failed)
        ->and($mission->error)->toBe('Budget épuisé.');
});

/**
 * Le pire cas : le process meurt sans jamais écrire son `result`. Une mission
 * qui resterait « en cours » pour toujours serait un mensonge affiché.
 */
test('a mission never stays running when the process dies silently', function () {
    Process::fake(['*' => Process::result(output: '', errorOutput: 'command not found: claude', exitCode: 127)]);

    $mission = Mission::factory()->create();

    (new RunMissionJob($mission->id))->handle(app(MissionCommandBuilder::class), app(ClaudeProcess::class));

    expect($mission->refresh()->status)->toBe(MissionStatus::Failed)
        ->and($mission->error)->toContain('command not found');
});

test('asking to stop sends a signal and lands on cancelled', function () {
    Process::fake([
        '*' => Process::describe()->output(fullStream())->runsFor(iterations: 2),
    ]);

    $mission = Mission::factory()->create();
    $mission->forceFill(['cancel_requested' => true])->save();

    (new RunMissionJob($mission->id))->handle(app(MissionCommandBuilder::class), app(ClaudeProcess::class));

    expect($mission->refresh()->status)->toBe(MissionStatus::Cancelled)
        ->and($mission->finished_at)->not->toBeNull();
});

test('a mission already running is not run a second time', function () {
    Process::fake();

    $mission = Mission::factory()->running()->create();

    (new RunMissionJob($mission->id))->handle(app(MissionCommandBuilder::class), app(ClaudeProcess::class));

    Process::assertNothingRan();
    expect(MissionEvent::where('mission_id', $mission->id)->count())->toBe(0);
});

test('the mission runs in its agent workspace', function () {
    Process::fake(['*' => Process::result(output: fullStream())]);

    $agent = TeamAgent::factory()->onRepo(sys_get_temp_dir())->create();
    $mission = Mission::factory()->for($agent, 'teamAgent')->create(['workspace_path' => sys_get_temp_dir()]);

    (new RunMissionJob($mission->id))->handle(app(MissionCommandBuilder::class), app(ClaudeProcess::class));

    Process::assertRan(fn ($process) => $process->path === sys_get_temp_dir());
});
