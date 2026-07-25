<?php

use App\Models\McpCall;
use App\Models\User;
use App\Support\Mcp\McpSettings;
use Livewire\Livewire;

test('shows the mcp endpoint url', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.index'))->assertSee('/mcp');
});

test('toggling mcp enabled persists immediately', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings-app')->set('mcpEnabled', true);

    expect((new McpSettings)->isEnabled())->toBeTrue();
});

test('generates a token that is shown once in clear', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test('pages::settings-app')->call('generateMcpToken');

    $token = $component->get('mcpNewToken');

    expect($token)->not->toBeNull()
        ->and((new McpSettings)->tokenMatches($token))->toBeTrue();

    $component->assertSee($token);
});

test('regenerating the token immediately revokes the previous one', function () {
    $this->actingAs(User::factory()->create());
    $settings = new McpSettings;
    $settings->setEnabled(true);
    $oldToken = $settings->generateToken();

    Livewire::test('pages::settings-app')->call('generateMcpToken');

    $this->postJson('/mcp', [], ['Authorization' => "Bearer {$oldToken}"])->assertStatus(401);
});

test('filters the call journal by tool and status', function () {
    $this->actingAs(User::factory()->create());
    McpCall::factory()->create(['tool' => 'capture', 'status' => 'success']);
    McpCall::factory()->create(['tool' => 'add_task', 'status' => 'error']);

    $byTool = Livewire::test('pages::settings-app')->set('mcpFilterTool', 'capture')->instance()->mcpCalls();
    expect($byTool->pluck('tool')->all())->toBe(['capture']);

    $byStatus = Livewire::test('pages::settings-app')->set('mcpFilterStatus', 'error')->instance()->mcpCalls();
    expect($byStatus->pluck('tool')->all())->toBe(['add_task']);
});

test('counts calls from the last 7 days', function () {
    $this->actingAs(User::factory()->create());
    McpCall::factory()->create(['created_at' => now()->subDays(2)]);
    McpCall::factory()->create(['created_at' => now()->subDays(10)]);

    $status = Livewire::test('pages::settings-app')->instance()->mcpStatus();

    expect($status['callsLast7Days'])->toBe(1);
});

test('shows the hermes connection block', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('settings.index'))
        ->assertSee('Configuration côté Hermes')
        ->assertSee('Authorization: Bearer', false)
        ->assertSee('172.17.0.1');
});
