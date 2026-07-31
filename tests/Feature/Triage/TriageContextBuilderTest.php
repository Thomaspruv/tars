<?php

use App\Enums\InboxSuggestionStatus;
use App\Models\Entity;
use App\Models\Goal;
use App\Models\InboxItem;
use App\Models\InboxSuggestion;
use App\Models\Task;
use App\Support\Triage\TriageContextBuilder;

test('pendingItems only returns unprocessed inbox items', function () {
    $pending = InboxItem::factory()->create();
    InboxItem::factory()->create(['processed_at' => now()]);

    $items = (new TriageContextBuilder)->pendingItems();

    expect($items)->toHaveCount(1)
        ->and($items->first()->id)->toBe($pending->id);
});

test('assembles the referential, pending items and open tasks sections', function () {
    Entity::factory()->create(['name' => 'Appart Lilas']);
    Goal::factory()->create(['title' => 'Rénover la salle de bain']);
    InboxItem::factory()->create(['content' => 'Rappeler Marc jeudi']);
    Task::factory()->create(['title' => 'Tâche ouverte existante', 'status' => 'todo']);

    $context = (new TriageContextBuilder)->build();

    expect($context)
        ->toContain('## Référentiel d\'ancrage')
        ->toContain('Appart Lilas')
        ->toContain('Rénover la salle de bain')
        ->toContain('## Items en attente')
        ->toContain('Rappeler Marc jeudi')
        ->toContain('## Tâches ouvertes')
        ->toContain('Tâche ouverte existante');
});

test('includes recent rejections for pending items, excludes rejections older than 30 days', function () {
    $item = InboxItem::factory()->create();

    InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'status' => InboxSuggestionStatus::Rejected,
        'suggested_type' => 'task',
        'created_at' => now()->subDays(10),
    ]);

    InboxSuggestion::factory()->create([
        'inbox_item_id' => $item->id,
        'status' => InboxSuggestionStatus::Rejected,
        'suggested_type' => 'note',
        'created_at' => now()->subDays(40),
    ]);

    $context = (new TriageContextBuilder)->build();

    expect($context)->toContain('## Refus récents (30 derniers jours)')
        ->toContain('task')
        ->not->toContain(': note —');
});
