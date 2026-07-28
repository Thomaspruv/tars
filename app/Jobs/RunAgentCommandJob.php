<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class RunAgentCommandJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $command,
        public readonly array $arguments = [],
    ) {}

    public function handle(): void
    {
        Artisan::call($this->command, $this->arguments);
    }
}
