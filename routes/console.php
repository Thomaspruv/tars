<?php

use App\Console\Commands\IndexBrainCommand;
use App\Console\Commands\SyncBrainCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(IndexBrainCommand::class, ['--scheduled'])->everyFifteenMinutes();
Schedule::command(SyncBrainCommand::class, ['--scheduled'])->everyMinute()->withoutOverlapping();
