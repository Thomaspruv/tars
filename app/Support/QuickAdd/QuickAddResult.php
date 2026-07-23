<?php

namespace App\Support\QuickAdd;

use App\Enums\TaskPriority;
use App\Models\Entity;
use App\Models\Goal;
use Carbon\CarbonInterface;

final readonly class QuickAddResult
{
    /**
     * @param  list<QuickAddToken>  $tokens
     */
    public function __construct(
        public string $title,
        public ?CarbonInterface $date,
        public ?Entity $entity,
        public ?Goal $goal,
        public ?TaskPriority $priority,
        public array $tokens,
    ) {}

    public function hasStructuredToken(): bool
    {
        return $this->date !== null || $this->entity !== null || $this->goal !== null || $this->priority !== null;
    }
}
