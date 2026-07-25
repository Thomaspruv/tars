<?php

use App\Support\Mcp\McpSettings;

test('rejects a request without a bearer token', function () {
    $settings = new McpSettings;
    $settings->setEnabled(true);
    $settings->generateToken();

    $this->postJson('/mcp')->assertStatus(401);
});

test('rejects a request with the wrong token', function () {
    $settings = new McpSettings;
    $settings->setEnabled(true);
    $settings->generateToken();

    $this->postJson('/mcp', [], ['Authorization' => 'Bearer wrong-token'])->assertStatus(401);
});

test('revoking a token by regenerating immediately rejects the old one', function () {
    $settings = new McpSettings;
    $settings->setEnabled(true);
    $oldToken = $settings->generateToken();

    $settings->generateToken();

    $this->postJson('/mcp', [], ['Authorization' => "Bearer {$oldToken}"])->assertStatus(401);
});

test('rejects any request when mcp is disabled, even with a valid token', function () {
    $settings = new McpSettings;
    $settings->setEnabled(false);
    $token = $settings->generateToken();

    $this->postJson('/mcp', [], ['Authorization' => "Bearer {$token}"])->assertStatus(503);
});

test('accepts a request with a valid token while enabled', function () {
    $settings = new McpSettings;
    $settings->setEnabled(true);
    $token = $settings->generateToken();

    $this->postJson('/mcp', [], ['Authorization' => "Bearer {$token}"])->assertStatus(200);
});

test('rejects a request when no token has ever been generated', function () {
    $settings = new McpSettings;
    $settings->setEnabled(true);

    $this->postJson('/mcp', [], ['Authorization' => 'Bearer whatever'])->assertStatus(401);
});
