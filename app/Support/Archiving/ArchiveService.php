<?php

namespace App\Support\Archiving;

use App\Enums\TaskStatus;
use App\Models\ChecklistItem;
use App\Models\Task;

class ArchiveService
{
    public function __construct(private readonly ArchiveSettings $settings = new ArchiveSettings) {}

    /**
     * Archives done tasks / checked list items older than the configured
     * threshold. A no-op while archiving is disabled (no threshold set).
     *
     * @return array{tasks: int, list_items: int}
     */
    public function archiveStaleItems(): array
    {
        $days = $this->settings->afterDays();

        if ($days === null) {
            return ['tasks' => 0, 'list_items' => 0];
        }

        $cutoff = now()->subDays($days);

        $tasks = Task::where('status', TaskStatus::Done)
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', $cutoff)
            ->update(['archived_at' => now()]);

        $listItems = ChecklistItem::whereNotNull('checked_at')
            ->where('checked_at', '<=', $cutoff)
            ->update(['archived_at' => now()]);

        return ['tasks' => $tasks, 'list_items' => $listItems];
    }
}
