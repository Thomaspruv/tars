<?php

use App\Console\Commands\ArchiveStaleItemsCommand;
use App\Console\Commands\CuratorConformanceCommand;
use App\Console\Commands\CuratorTidyCommand;
use App\Console\Commands\CuratorTodoCommand;
use App\Console\Commands\GeneratePlannerProposalCommand;
use App\Console\Commands\GenerateReviewCommand;
use App\Console\Commands\IndexBrainCommand;
use App\Console\Commands\QuestionnaireSchedulerCommand;
use App\Console\Commands\SyncBrainCommand;
use App\Console\Commands\SyncGoogleCalendarCommand;
use App\Console\Commands\TriageInboxCommand;
use App\Console\Commands\WatchMissionsCommand;
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
// Trois lots de 40 par dimanche : la migration du vault avance par paliers
// digestes, et Thomas n'a jamais plus de 120 propositions à relire d'un coup.
Schedule::command(CuratorConformanceCommand::class, ['--scheduled', '--limit=120'])->weeklyOn(0, '21:00')->withoutOverlapping();
Schedule::command(TriageInboxCommand::class, ['--scheduled'])->hourly()->withoutOverlapping();
Schedule::command(GeneratePlannerProposalCommand::class, ['--scheduled'])->everyMinute()->withoutOverlapping();
Schedule::command(QuestionnaireSchedulerCommand::class, ['--scheduled'])->hourly()->withoutOverlapping();
Schedule::command(ArchiveStaleItemsCommand::class, ['--scheduled'])->daily();
Schedule::command(SyncGoogleCalendarCommand::class, ['--scheduled'])->everyFiveMinutes()->withoutOverlapping();
// Une veille se réveille à la minute : c'est la granularité la plus fine qu'on
// puisse lui offrir, et l'intervalle réel est porté par la mission elle-même.
Schedule::command(WatchMissionsCommand::class, ['--scheduled'])->everyMinute()->withoutOverlapping();
