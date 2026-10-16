<?php

namespace App\Support\Questionnaire;

use App\Enums\QuestionnaireRunStatus;
use App\Models\QuestionnaireRun;
use App\Support\Brain\Schema\FrontmatterBuilder;
use App\Support\Brain\Schema\NoteType;
use App\Support\Brain\VaultWriter;
use Illuminate\Support\Str;

class QuestionnaireCompleter
{
    public function __construct(
        private readonly VaultWriter $writer,
        private readonly FrontmatterBuilder $frontmatter,
    ) {}

    /**
     * Writes the vault note for a completed run (questions + answers,
     * frontmatter carrying the numeric answers), commits, reindexes, and
     * marks the run completed. Reused by both the MCP answer_questionnaire
     * tool and the in-app fill-in form — the only two ways a run completes.
     */
    public function complete(QuestionnaireRun $run): void
    {
        if ($run->status === QuestionnaireRunStatus::Completed) {
            return;
        }

        $run->loadMissing(['questionnaire', 'answers']);

        $relativePath = "TARS/Journal/Bilans/{$run->due_date->toDateString()} — {$run->questionnaire->name}.md";

        $numericAnswers = [];
        $lines = [];

        foreach ($run->answers as $answer) {
            $lines[] = "## {$answer->question_text}";
            $lines[] = $answer->answer_numeric !== null ? (string) $answer->answer_numeric : (string) $answer->answer_text;
            $lines[] = '';

            if ($answer->answer_numeric !== null) {
                $numericAnswers[Str::slug($answer->question_text)] = $answer->answer_numeric;
            }
        }

        $frontmatter = $this->frontmatter->build(
            type: NoteType::Journal,
            title: "{$run->questionnaire->name} — {$run->due_date->toDateString()}",
            date: $run->due_date,
            source: 'thomas',
            extra: ['questionnaire' => $run->questionnaire->name, ...$numericAnswers],
        );

        $result = $this->writer->write(
            $relativePath,
            $frontmatter,
            trim(implode("\n", $lines))."\n",
            "bilan {$run->questionnaire->name} ({$run->due_date->toDateString()})",
        );

        // The answers are safe in the database either way; the note_path is
        // only set when the file really landed.
        if (! $result->isWritten()) {
            report(new \RuntimeException("Bilan « {$run->questionnaire->name} » non écrit dans le vault : ".$result->message()));
        }

        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'note_path' => $result->isWritten() ? $relativePath : $result->sasPath,
        ]);
    }
}
