<?php

use App\Jobs\RunAgentCommandJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;

test('handle calls the given artisan command', function () {
    Artisan::shouldReceive('call')->once()->with('review:generate');

    (new RunAgentCommandJob('review:generate'))->handle();
});

test('is queueable', function () {
    expect(new RunAgentCommandJob('curator:todo'))->toBeInstanceOf(ShouldQueue::class);
});
