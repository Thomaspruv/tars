<?php

namespace App\Support\Brain;

readonly class GitPullResult
{
    public function __construct(
        public bool $successful,
        public string $message,
    ) {}
}
