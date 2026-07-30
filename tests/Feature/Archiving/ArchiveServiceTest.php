<?php

use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Goal;
use App\Models\Task;
use App\Support\Archiving\ArchiveService;
use App\Support\Archiving\ArchiveSettings;

test('does nothing when archiving is disabled', function () {
    $task = Task::factory()->done()->create(['completed_at' => now()->subDays(30)]);

    $result = (new ArchiveService)->archiveStaleItems();

    expect($result)->toBe(['tasks' => 0, 'list_items' => 0]);
    expect($task->fresh()->archived_at)->toBeNull();
});

test('archives done tasks older than the configured threshold, leaves recent ones', function () {
    (new ArchiveSettings)->updateAfterDays(7);

    $stale = Task::factory()->done()->create(['completed_at' => now()->subDays(10)]);
    $recent = Task::factory()->done()->create(['completed_at' => now()->subDays(2)]);
    $open = Task::factory()->create(['status' => 'todo']);

    $result = (new ArchiveService)->archiveStaleItems();

    expect($result['tasks'])->toBe(1);
    expect($stale->fresh()->archived_at)->not->toBeNull();
    expect($recent->fresh()->archived_at)->toBeNull();
    expect($open->fresh()->archived_at)->toBeNull();
});

test('archives checked list items older than the configured threshold, leaves recent ones', function () {
    (new ArchiveSettings)->updateAfterDays(7);

    $list = Checklist::factory()->create();
    $stale = ChecklistItem::factory()->create(['list_id' => $list->id, 'checked_at' => now()->subDays(10)]);
    $recent = ChecklistItem::factory()->create(['list_id' => $list->id, 'checked_at' => now()->subDays(2)]);
    $unchecked = ChecklistItem::factory()->create(['list_id' => $list->id, 'checked_at' => null]);

    $result = (new ArchiveService)->archiveStaleItems();

    expect($result['list_items'])->toBe(1);
    expect($stale->fresh()->archived_at)->not->toBeNull();
    expect($recent->fresh()->archived_at)->toBeNull();
    expect($unchecked->fresh()->archived_at)->toBeNull();
});

test('is idempotent — running it twice does not re-count already archived rows', function () {
    (new ArchiveSettings)->updateAfterDays(7);

    Task::factory()->done()->create(['completed_at' => now()->subDays(10)]);

    $service = new ArchiveService;
    $service->archiveStaleItems();
    $second = $service->archiveStaleItems();

    expect($second)->toBe(['tasks' => 0, 'list_items' => 0]);
});

test('archived tasks and list items are excluded from default queries and relations', function () {
    (new ArchiveSettings)->updateAfterDays(7);

    $goal = Goal::factory()->create();
    $archivedTask = Task::factory()->done()->create(['goal_id' => $goal->id, 'completed_at' => now()->subDays(10)]);
    $openTask = Task::factory()->create(['goal_id' => $goal->id, 'status' => 'todo']);

    $list = Checklist::factory()->create();
    $archivedItem = ChecklistItem::factory()->create(['list_id' => $list->id, 'checked_at' => now()->subDays(10)]);
    $uncheckedItem = ChecklistItem::factory()->create(['list_id' => $list->id, 'checked_at' => null]);

    (new ArchiveService)->archiveStaleItems();

    expect(Task::all()->pluck('id'))->not->toContain($archivedTask->id)->toContain($openTask->id);
    expect($goal->fresh()->tasks->pluck('id'))->not->toContain($archivedTask->id)->toContain($openTask->id);
    expect(Task::withArchived()->pluck('id'))->toContain($archivedTask->id);

    expect(ChecklistItem::all()->pluck('id'))->not->toContain($archivedItem->id)->toContain($uncheckedItem->id);
    expect($list->fresh()->items->pluck('id'))->not->toContain($archivedItem->id)->toContain($uncheckedItem->id);
    expect(ChecklistItem::withArchived()->pluck('id'))->toContain($archivedItem->id);
});
