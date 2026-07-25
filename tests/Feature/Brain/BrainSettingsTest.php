<?php

use App\Support\Brain\BrainSettings;

test('falls back to config defaults when nothing is stored', function () {
    config(['brain.remote_url' => null, 'brain.branch' => 'main', 'brain.sync_frequency_minutes' => 15, 'brain.auto_index' => true]);

    $settings = new BrainSettings;

    expect($settings->remoteUrl())->toBeNull()
        ->and($settings->branch())->toBe('main')
        ->and($settings->syncFrequencyMinutes())->toBe(15)
        ->and($settings->autoIndexEnabled())->toBeTrue()
        ->and($settings->isConfigured())->toBeFalse();
});

test('stored configuration overrides config defaults', function () {
    $settings = new BrainSettings;

    $settings->updateConfiguration([
        'remote_url' => 'git@example.com:vault.git',
        'branch' => 'develop',
        'sync_frequency_minutes' => 30,
        'auto_index' => false,
    ]);

    expect($settings->remoteUrl())->toBe('git@example.com:vault.git')
        ->and($settings->branch())->toBe('develop')
        ->and($settings->syncFrequencyMinutes())->toBe(30)
        ->and($settings->autoIndexEnabled())->toBeFalse()
        ->and($settings->isConfigured())->toBeTrue();
});

test('marks synced and indexed timestamps', function () {
    $settings = new BrainSettings;

    expect($settings->lastSyncedAt())->toBeNull()
        ->and($settings->lastIndexedAt())->toBeNull();

    $settings->markSynced();
    $settings->markIndexed();

    expect($settings->lastSyncedAt())->not->toBeNull()
        ->and($settings->lastIndexedAt())->not->toBeNull();
});
