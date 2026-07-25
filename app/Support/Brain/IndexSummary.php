<?php

namespace App\Support\Brain;

readonly class IndexSummary
{
    public function __construct(
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $deleted,
    ) {}
}
