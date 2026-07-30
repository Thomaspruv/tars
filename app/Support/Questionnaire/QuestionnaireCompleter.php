<?php

namespace App\Support\Questionnaire;

use App\Enums\QuestionnaireRunStatus;
use App\Models\QuestionnaireRun;
use App\Support\Brain\BrainSettings;
use App\Support\Brain\GitRepository;
use App\Support\Brain\VaultIndexer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class QuestionnaireCompleter
{
    public function __construct(
        private readonly BrainSettings $settings,
        private readonly GitRepository $git,
        private readonly VaultIndexer $indexer,
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

        $vaultPath = rtrim($this->settings->localPath(), '/');
        $relativePath = "TARS/Journal/Bilans/{$run->due_date->toDateString()} — {$run->questionnaire->name}.md";
        $absolutePath = "{$vaultPath}/{$relativePath}";

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

        $frontmatter = [
            'date' => $run->due_date->toDateString(),
            'questionnaire' => $run->questionnaire->name,
            'source' => 'bilan',
            ...$numericAnswers,
        ];

        $content = "---\n".Yaml::dump($frontmatter)."---\n\n".trim(implode("\n", $lines))."\n";

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, $content);
        $this->git->commit($vaultPath, $relativePath, "tars: bilan {$run->questionnaire->name} ({$run->due_date->toDateString()})");
        $this->indexer->indexFile($absolutePath);

        $run->update(['status' => 'completed', 'completed_at' => now(), 'note_path' => $relativePath]);
    }
}
