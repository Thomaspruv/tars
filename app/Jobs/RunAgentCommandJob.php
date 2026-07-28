<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class RunAgentCommandJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $command) {}

    public function handle(): void
    {
        Artisan::call($this->command);
    }
}
