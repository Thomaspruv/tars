<?php

namespace App\Console\Commands;

use App\Support\Archiving\ArchiveService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('archive:stale {--scheduled}')]
#[Description('Archive les tâches terminées et éléments de liste cochés depuis plus longtemps que le délai configuré')]
class ArchiveStaleItemsCommand extends Command
{
    public function handle(ArchiveService $service): int
    {
        $result = $service->archiveStaleItems();

        if (! $this->option('scheduled')) {
            $this->info("{$result['tasks']} tâche(s) et {$result['list_items']} élément(s) de liste archivés.");
        }

        return self::SUCCESS;
    }
}
