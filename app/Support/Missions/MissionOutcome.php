<?php

namespace App\Support\Missions;

class MissionOutcome
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $errorOutput,
        public readonly bool $stopped = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
