<?php

namespace App\Support\Brain;

readonly class GitPullResult
{
    /**
     * @param  ?string  $warning  set when the pull did not happen but the write
     *                            may still proceed — the vault is simply not up to date.
     */
    public function __construct(
        public bool $successful,
        public string $message,
        public ?string $warning = null,
    ) {}
}
