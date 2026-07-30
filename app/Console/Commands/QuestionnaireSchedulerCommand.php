<?php

namespace App\Console\Commands;

use App\Support\Questionnaire\QuestionnaireScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('questionnaires:schedule {--scheduled}')]
#[Description('Crée les runs de questionnaires arrivés à échéance')]
class QuestionnaireSchedulerCommand extends Command
{
    public function handle(QuestionnaireScheduler $scheduler): int
    {
        $created = $scheduler->ensureRunsCreated();

        if (! $this->option('scheduled')) {
            $this->info("{$created} run(s) créé(s).");
        }

        return self::SUCCESS;
    }
}
