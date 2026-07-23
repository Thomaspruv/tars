<?php

namespace App\Support\QuickAdd;

final readonly class QuickAddToken
{
    public function __construct(
        public string $type,
        public string $raw,
    ) {}
}
