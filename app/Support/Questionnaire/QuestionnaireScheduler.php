<?php

namespace App\Support\Questionnaire;

use App\Models\Questionnaire;
use App\Support\Recurrence\RecurrenceCalculator;

class QuestionnaireScheduler
{
    public function __construct(private readonly RecurrenceCalculator $calculator = new RecurrenceCalculator) {}

    /**
     * Creates any run whose due date has arrived. A pending run never blocks
     * the next one — the next occurrence is always computed from the last
     * known due date, not from whether that run was completed.
     */
    public function ensureRunsCreated(): int
    {
        return Questionnaire::where('is_active', true)->get()
            ->filter(fn (Questionnaire $questionnaire): bool => $this->ensureRunCreated($questionnaire))
            ->count();
    }

    private function ensureRunCreated(Questionnaire $questionnaire): bool
    {
        $lastRun = $questionnaire->runs()->orderByDesc('due_date')->first();
        $from = $lastRun !== null ? $lastRun->due_date : $questionnaire->created_at;

        $pattern = "{$questionnaire->frequency->value}:{$questionnaire->anchor}";
        $nextDue = $this->calculator->nextOccurrence($pattern, $from);

        if ($nextDue->isFuture()) {
            return false;
        }

        $alreadyExists = $questionnaire->runs()->whereDate('due_date', $nextDue->toDateString())->exists();

        if ($alreadyExists) {
            return false;
        }

        $questionnaire->runs()->create([
            'due_date' => $nextDue,
            'status' => 'pending',
        ]);

        return true;
    }
}
