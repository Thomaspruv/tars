<?php

use App\Console\Commands\ArchiveStaleItemsCommand;
use App\Console\Commands\CuratorTidyCommand;
use App\Console\Commands\CuratorTodoCommand;
use App\Console\Commands\GeneratePlannerProposalCommand;
use App\Console\Commands\GenerateReviewCommand;
use App\Console\Commands\IndexBrainCommand;
use App\Console\Commands\QuestionnaireSchedulerCommand;
use App\Console\Commands\SyncBrainCommand;
use App\Console\Commands\TriageInboxCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(IndexBrainCommand::class, ['--scheduled'])->everyFifteenMinutes();
Schedule::command(SyncBrainCommand::class, ['--scheduled'])->everyMinute()->withoutOverlapping();
Schedule::command(GenerateReviewCommand::class, ['--scheduled'])->everyMinute()->withoutOverlapping();
Schedule::command(CuratorTodoCommand::class, ['--scheduled'])->hourly()->withoutOverlapping();
Schedule::command(CuratorTidyCommand::class, ['--scheduled'])->dailyAt('22:00')->withoutOverlapping();
Schedule::command(TriageInboxCommand::class, ['--scheduled'])->hourly()->withoutOverlapping();
Schedule::command(GeneratePlannerProposalCommand::class, ['--scheduled'])->everyMinute()->withoutOverlapping();
Schedule::command(QuestionnaireSchedulerCommand::class, ['--scheduled'])->hourly()->withoutOverlapping();
Schedule::command(ArchiveStaleItemsCommand::class, ['--scheduled'])->daily();
